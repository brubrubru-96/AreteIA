<?php
namespace local_areteia;

defined('MOODLE_INTERNAL') || die();

/**
 * Handles the three server-side actions that redirect: sync, ingest, export.
 *
 * Each action processes data, then issues a Moodle redirect().
 * After calling handle(), the script never continues (redirect dies).
 */
class action_handler {

    /**
     * Dispatch the given action. Returns false if the action is not recognized
     * (so the caller can continue rendering). On recognized actions, this method
     * never returns — it calls redirect() which dies.
     *
     * @param string     $action    One of: 'sync', 'ingest', 'export'
     * @param int        $course_id
     * @param \moodle_url $base_url  The current $PAGE->url
     * @param bool       $is_ajax
     * @return bool  false if action not handled
     */
    public static function handle(string $action, int $course_id, \moodle_url $base_url, bool $is_ajax): bool {
        switch ($action) {
            case 'sync':
                self::handle_sync($course_id, $base_url, $is_ajax);
                return true; // never reached

            case 'ingest':
                require_sesskey();
                self::handle_ingest($course_id, $base_url, $is_ajax);
                return true;

            case 'export':
                require_sesskey();
                self::handle_export($course_id, $base_url, $is_ajax);
                return true;

            case 'delete_rag':
                require_sesskey();
                self::handle_delete_rag($course_id, $base_url, $is_ajax);
                return true;

            case 'preview':
                self::handle_preview($course_id);
                return true;

            case 'inject_quiz':
                require_sesskey();
                self::handle_inject_quiz($course_id, $base_url, $is_ajax);
                return true;

            case 'inject_assign':
                require_sesskey();
                self::handle_inject_assign($course_id, $base_url, $is_ajax);
                return true;

            case 'inject_forum':
                require_sesskey();
                self::handle_inject_forum($course_id, $base_url, $is_ajax);
                return true;

            case 'export_pdf':
                self::handle_export_pdf($course_id);
                return true;

            default:
                return false;
        }
    }

    // ------------------------------------------------------------------
    // Individual action handlers
    // ------------------------------------------------------------------

    /**
     * Sync course files to the Python service and redirect to Step 1.
     */
    private static function handle_sync(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        // Extract files to sync dir + get summary
        data_provider::get_course_files($course_id, true);
        $summary = data_provider::get_course_summary($course_id);

        // POST /sync
        rag_client::sync($summary);

        // Always return to the Library tab (Step 1)
        $redir = new \moodle_url($base_url, ['step' => 1, 'use_moodle' => 1, 'action' => 'lib']);
        if ($is_ajax) {
            // For AJAX: return JSON with redirect URL, avoid Moodle's redirect() which mutates session
            header('Content-Type: application/json');
            echo json_encode(['redirect' => $redir->out(false)]);
            if (ob_get_level() > 0) ob_end_clean();
            exit();
        }
        redirect($redir);
    }

    /**
     * Trigger embedding ingestion and redirect to Step 1 with result status.
     */
    private static function handle_ingest(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        global $CFG;
        \core_php_time_limit::raise(600);

        // 1. Force a clean physical extraction of ALL allowed course files to disk.
        data_provider::get_course_files($course_id, true);

        // 2. Filter: only keep user-selected files on disk before calling Python.
        $selected_files_raw = optional_param('selected_files', '', PARAM_RAW);
        error_log("[AreteIA] handle_ingest course={$course_id} selected_files_raw=" . substr($selected_files_raw, 0, 300));

        // Always define base_sync_dir (used below regardless of branch taken)
        $base_sync_dir = rtrim($CFG->dataroot . '/areteia_sync/course_' . $course_id, '/');

        if (!empty($selected_files_raw)) {
            $selected_files = json_decode($selected_files_raw, true);

            if (is_array($selected_files) && count($selected_files) > 0) {
                // Normalize: trim whitespace and unify directory separators
                $selected_files = array_map(function($p) {
                    return str_replace('\\', '/', trim($p));
                }, $selected_files);

                error_log("[AreteIA] Selected files (" . count($selected_files) . "): " . implode(', ', $selected_files));

                if (file_exists($base_sync_dir)) {
                    $directory = new \RecursiveDirectoryIterator($base_sync_dir, \RecursiveDirectoryIterator::SKIP_DOTS);
                    $iterator  = new \RecursiveIteratorIterator($directory);

                    foreach ($iterator as $file) {
                        if ($file->isDir()) continue;

                        // Relative path from course sync dir, normalized to forward slashes
                        $relative_path = str_replace('\\', '/', substr($file->getPathname(), strlen($base_sync_dir) + 1));

                        if (!in_array($relative_path, $selected_files)) {
                            error_log("[AreteIA] Deleting unselected: {$relative_path}");
                            @unlink($file->getPathname());
                        } else {
                            error_log("[AreteIA] Keeping selected: {$relative_path}");
                        }
                    }
                }
            } else {
                error_log("[AreteIA] WARNING: selected_files JSON decoded to empty/non-array. Raw: " . $selected_files_raw);
            }
        } else {
            error_log("[AreteIA] WARNING: selected_files is empty — ingesting ALL files.");
        }

        $res_data = rag_client::ingest($course_id, $selected_files ?? [], $base_sync_dir);

        // Determine ingestion state: 1=success, 2=empty, 3=processing, -1=error
        if ($res_data && $res_data->status == 'success') {
            $state = ($res_data->chunks > 0) ? 1 : 2;
        } else if ($res_data && $res_data->status == 'started') {
            $state = 3;
        } else {
            $state = -1;
        }
        if ($res_data && isset($res_data->chunks) && $res_data->chunks === 0) {
            $state = 2;
        }

        // Always return to the Library tab (Step 1)
        // Set deleted=1 param so UI can potentially show a small flash message (optional)
        $redir = new \moodle_url($base_url, ['step' => 1, 'use_moodle' => 1, 'ingested' => $state, 'deleted' => 1, 'action' => 'lib']);
        if ($is_ajax) {
            $redir->param('ajax', 1);
        }
        redirect($redir);
    }

    /**
     * Delete the existing RAG embedding for a course.
     */
    private static function handle_delete_rag(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        rag_client::delete($course_id);
        data_provider::delete_sync_dir($course_id);
        
        $redir = new \moodle_url($base_url, ['step' => 0, 'action' => 'lib', 'force_step' => 0]);
        if ($is_ajax) {
            $redir->param('ajax', 1);
        }
        redirect($redir);
    }

    /**
     * Export the generated instrument + rubric as a Moodle Assign activity.
     */
    private static function handle_export(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        $inst_name = session_manager::get('instrument', '') . ' - AreteIA';
        $final_desc = session_manager::get('inst_content', '');

        $rubric = session_manager::get('rubric_content', '');
        if (!empty($rubric)) {
            $final_desc .= "\n\n### Rúbrica\n" . $rubric;
        }

        if (!$inst_name) {
            $inst_name = 'Evaluación AreteIA';
        }
        if (!$final_desc) {
            $final_desc = 'Instrumento generado por AreteIA.';
        }

        $moduleinfo = \local_areteia\data_provider::create_assign_activity($course_id, $inst_name, $final_desc);

        // Force a valid tab action to avoid infinite redirect loop
        $action = optional_param('action', 'eval', PARAM_ALPHA);
        if ($action === 'export') {
            $action = 'eval';
        }

        $redir = new \moodle_url($base_url, [
            'step'     => 7,
            'exported' => 1,
            'cmid'     => $moduleinfo->coursemodule,
            'action'   => $action,
        ]);
        if ($is_ajax) {
            $redir->param('ajax', 1);
        }
        redirect($redir);
    }

    private static function handle_inject_quiz(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        $section_num = optional_param('section_num', 0, PARAM_INT);

        // 1. Obtener el puntaje total máximo solicitado (Ej: 7.0 o 100.0)
        $max_grade = optional_param('max_grade', 100.0, PARAM_FLOAT);

        // LEER DESDE SESIÓN: Ya no dependemos del payload pesado por POST
        $raw_selection = session_manager::get('final_selection_json', '');

        $questions = [];
        if (!empty($raw_selection)) {
            $parsed = json_decode($raw_selection, true);
            if (is_array($parsed) && !empty($parsed['items'])) {
                $questions = $parsed['items'];
                
                // Read point distribution securely from POST directly
                $item_points = optional_param_array('item_points', [], PARAM_RAW);
                foreach ($questions as $idx => &$q) {
                    if (isset($item_points[$idx])) {
                        $weight_percentage = (float)$item_points[$idx];
                        $q['weight'] = $weight_percentage; // Persist the percentage weight
                        $q['points'] = round(($weight_percentage / 100.0) * $max_grade, 2); // Calculate absolute points
                    }
                }
                unset($q); // break reference
                
                // Actualizar la sesión con los pesos finales configurados por el usuario antes de inyectar
                $parsed['items'] = $questions;
                session_manager::set('final_selection_json', json_encode($parsed));
            }
        }

        // 2. Si no hay preguntas, error
        if (empty($questions)) {
            $redir = new \moodle_url($base_url, [
                'step'         => 5,
                'quiz_error'   => 1,
                'message'      => 'No se detectó una selección válida de ítems.'
            ]);
            redirect($redir);
        }

        try {
            // Se pasa el max_grade además de name (si se desea uno por defecto se pasa null o string custom)
            $result = \local_areteia\data_provider::create_quiz_activity($course_id, $section_num, $questions, 'Cuestionario AreteIA', $max_grade);
            if (!$result || !isset($result['coursemodule'])) {
                throw new \moodle_exception('error_creating_quiz', 'local_areteia', '', null, 'Result is empty or invalid');
            }
            $quiz_cmid = $result['coursemodule'];
        } catch (\Throwable $e) {
            error_log('[AreteIA] inject_quiz error in course ' . $course_id . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            $redir = new \moodle_url($base_url, [
                'step'         => 7,
                'action'       => 'eval',
                'quiz_error'   => 1,
            ]);
            redirect($redir);
        }

        $redir = new \moodle_url($base_url, [
            'step'         => 7,
            'action'       => 'eval',
            'quiz_injected'=> 1,
            'quiz_cmid'    => $quiz_cmid,
        ]);
        if ($is_ajax) {
            $redir->param('ajax', 1);
        }
        redirect($redir);
    }


    /**
     * Returns the fake questions for quiz injection.
     * Moved from step7 to action_handler for better availability.
     */
    public static function get_fake_questions(): array {
        return [
            [
                'type'    => 'multichoice',
                'text'    => 'Cual de los siguientes es un ejemplo de evaluacion formativa?',
                'options' => [
                    'Examen final del semestre',
                    'Retroalimentacion continua durante el proceso de aprendizaje',
                    'Prueba de admision universitaria',
                    'Calificacion numerica trimestral',
                ],
                'correct' => 1,
            ],
            [
                'type'    => 'truefalse',
                'text'    => 'La taxonomia de Bloom clasifica los objetivos de aprendizaje en niveles cognitivos jerarquicos.',
                'correct' => true,
            ],
            [
                'type' => 'essay',
                'text' => 'Describe como diseñarias una evaluacion autentica para tu asignatura. Fundamenta tu respuesta considerando el contexto pedagogico del curso.',
            ],
        ];
    }

    /**
     * Export the generated instrument as an Assign activity (with section selection).
     */
    private static function handle_inject_assign(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        $section_num = optional_param('section_num', 0, PARAM_INT);
        $inst_name = session_manager::get('instrument', '') . ' - AreteIA';
        $inst_content = session_manager::get('inst_content', '');
        $rubric_content = session_manager::get('rubric_content', '');

        // Build a rich description from the instrument items
        $description = self::build_activity_description($inst_content, $rubric_content);

        if (!$inst_name || $inst_name === ' - AreteIA') {
            $inst_name = 'Evaluación AreteIA';
        }

        try {
            $moduleinfo = data_provider::create_assign_activity($course_id, $inst_name, $description, $section_num);
            $cmid = $moduleinfo->coursemodule;
        } catch (\Throwable $e) {
            error_log('[AreteIA] inject_assign error: ' . $e->getMessage());
            $redir = new \moodle_url($base_url, [
                'step'       => 7,
                'action'     => 'eval',
                'export_error' => 1,
            ]);
            redirect($redir);
        }

        $redir = new \moodle_url($base_url, [
            'step'          => 7,
            'action'        => 'eval',
            'assign_injected' => 1,
            'assign_cmid'   => $cmid,
        ]);
        if ($is_ajax) {
            $redir->param('ajax', 1);
        }
        redirect($redir);
    }

    /**
     * Export the generated instrument as a Forum activity (with section selection).
     */
    private static function handle_inject_forum(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        $section_num = optional_param('section_num', 0, PARAM_INT);
        $inst_name = session_manager::get('instrument', '') . ' - AreteIA';
        $inst_content = session_manager::get('inst_content', '');
        $rubric_content = session_manager::get('rubric_content', '');

        $description = self::build_activity_description($inst_content, $rubric_content);

        if (!$inst_name || $inst_name === ' - AreteIA') {
            $inst_name = 'Debate AreteIA';
        }

        try {
            $moduleinfo = data_provider::create_forum_activity($course_id, $inst_name, $description, $section_num);
            $cmid = $moduleinfo->coursemodule;
        } catch (\Throwable $e) {
            error_log('[AreteIA] inject_forum error: ' . $e->getMessage());
            $redir = new \moodle_url($base_url, [
                'step'       => 7,
                'action'     => 'eval',
                'export_error' => 1,
            ]);
            redirect($redir);
        }

        $redir = new \moodle_url($base_url, [
            'step'          => 7,
            'action'        => 'eval',
            'forum_injected' => 1,
            'forum_cmid'    => $cmid,
        ]);
        if ($is_ajax) {
            $redir->param('ajax', 1);
        }
        redirect($redir);
    }

    /**
     * Build a rich Markdown description from the structured instrument content.
     */
    private static function build_activity_description(string $inst_content, string $rubric_content): string {
        $parts = [];
        $data = @json_decode($inst_content, true);

        if (is_array($data)) {
            if (!empty($data['title'])) {
                $parts[] = '## ' . $data['title'];
            }

            foreach (($data['items'] ?? []) as $idx => $item) {
                $num = $idx + 1;
                $type_label = $item['type'] ?? 'Ítem';
                $difficulty = $item['difficulty'] ?? '';
                $header = "### Ítem {$num} — {$type_label}";
                if ($difficulty) {
                    $header .= " ({$difficulty})";
                }
                $parts[] = $header;
                $parts[] = $item['consiga'] ?? $item['text'] ?? '';

                // Add options if present (for reference)
                if (!empty($item['alternativas'])) {
                    $parts[] = '';
                    foreach ($item['alternativas'] as $oi => $opt) {
                        $letter = chr(65 + $oi); // A, B, C, ...
                        $parts[] = "{$letter}. {$opt}";
                    }
                }

                // Add objectives
                if (!empty($item['objectives'])) {
                    $parts[] = '';
                    $parts[] = '**Objetivos:** ' . implode(', ', $item['objectives']);
                }
                $parts[] = ''; // spacer
            }

            if (!empty($data['justification'])) {
                $parts[] = '---';
                $parts[] = '**Justificación Pedagógica:** ' . $data['justification'];
            }
        } else {
            // Fallback: use raw content as-is
            $parts[] = $inst_content ?: 'Instrumento generado por AreteIA.';
        }

        if (!empty($rubric_content)) {
            $parts[] = '';
            $parts[] = '---';
            $parts[] = '## Rúbrica';
            $parts[] = $rubric_content;
        }

        return implode("\n", $parts);
    }

    /**
     * Fetch LLM prompt preview and return as JSON.
     */
    private static function handle_preview(int $course_id): void {
        header('Content-Type: application/json');
        
        $step     = optional_param('p_step', 4, PARAM_INT);
        $feedback = optional_param('feedback', session_manager::get('feedback', ''), PARAM_TEXT);
        
        $summary = data_provider::get_course_summary($course_id);
        
        $data = [
            'course_id'          => $course_id,
            'step'               => $step,
            'objective'          => session_manager::get('d2', ''),
            'objective_json'     => session_manager::get('d2_json', ''),
            'dimensions'         => "Contenido: " . session_manager::get('d1', '') . 
                                   ", Función: " . session_manager::get('d3', '') . 
                                   ", Modalidad: " . session_manager::get('d4', ''),
            'd1_content'         => session_manager::get('d1', ''),
            'd3_function'        => session_manager::get('d3', ''),
            'd4_modality'        => session_manager::get('d4', ''),
            'feedback'           => $feedback,
            'chosen_instrument'  => session_manager::get('instrument') ?: session_manager::get('sel_sug', ''),
            'instrument_content' => session_manager::get('inst_content', ''),
        ];

        $res = rag_client::preview_prompt($data);
        echo json_encode($res ?: ['status' => 'error', 'message' => 'Servicio de IA no disponible']);
        die();
    }

    /**
     * Export the generated instrument as a printable HTML file (can also be opened in Word).
     * No sesskey required — read-only download.
     */
    private static function handle_export_pdf(int $course_id): void {
        global $CFG;

        $instrument = session_manager::get('instrument', 'Instrumento AreteIA');
        $raw        = session_manager::get('inst_content', '');
        $data       = json_decode($raw, true) ?? [];

        $title      = htmlspecialchars($data['title']   ?? $instrument, ENT_QUOTES, 'UTF-8');
        $scenario   = $data['scenario']   ?? '';
        $items      = $data['items']      ?? [];
        $justif     = $data['justification'] ?? '';

        $rubric_raw  = session_manager::get('rubric_content', '');
        $rubric_data = json_decode($rubric_raw, true) ?? [];

        // ── Build HTML ──────────────────────────────────────────────────
        $date_str = date('d/m/Y');
        $h  = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">';
        $h .= '<title>' . $title . '</title>';
        $h .= '<style>';
        $h .= 'body{font-family:Georgia,serif;font-size:12pt;line-height:1.6;max-width:820px;margin:0 auto;padding:30px;color:#222;}';
        $h .= 'h1{font-size:18pt;border-bottom:2px solid #6c63ff;padding-bottom:8px;margin-bottom:20px;color:#2d2d6d;}';
        $h .= 'h2{font-size:13pt;color:#4a4a8a;margin-top:24px;margin-bottom:8px;}';
        $h .= '.scenario{background:#fffbea;border-left:5px solid #f59e0b;padding:14px 18px;margin:16px 0;border-radius:4px;}';
        $h .= '.item{border-left:3px solid #d0d0f0;padding:10px 16px;margin:10px 0;background:#fafafa;}';
        $h .= '.item-meta{font-size:9pt;color:#888;margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px;}';
        $h .= 'ol{padding-left:20px;} ol li{margin-bottom:12px;}';
        $h .= '.alts{margin-top:6px;padding-left:20px;} .alts li{margin-bottom:3px;font-size:11pt;}';
        $h .= '.justification{font-size:10pt;color:#555;border-top:1px solid #ddd;margin-top:24px;padding-top:12px;font-style:italic;}';
        $h .= '.rubric-table{width:100%;border-collapse:collapse;margin-top:10px;font-size:10pt;}';
        $h .= '.rubric-table th{background:#6c63ff;color:#fff;padding:8px;text-align:left;}';
        $h .= '.rubric-table td{border:1px solid #ddd;padding:8px;vertical-align:top;}';
        $h .= '.footer{font-size:9pt;color:#aaa;text-align:center;margin-top:30px;border-top:1px solid #eee;padding-top:10px;}';
        $h .= '@media print{body{max-width:none;padding:10mm;} .footer{position:fixed;bottom:0;width:100%;}}';
        $h .= '</style></head><body>';

        $h .= '<h1>' . $title . '</h1>';
        $h .= '<p style="font-size:10pt;color:#999;margin-top:-15px;margin-bottom:20px;">Generado con AreteIA · ' . $date_str . '</p>';

        // Scenario
        if (!empty($scenario)) {
            $h .= '<h2>📖 Escenario / Contexto</h2>';
            $h .= '<div class="scenario">' . nl2br(htmlspecialchars($scenario, ENT_QUOTES, 'UTF-8')) . '</div>';
        }

        // Items
        if (!empty($items)) {
            $h .= '<h2>📋 Consignas de Evaluación</h2><ol>';
            foreach ($items as $item) {
                $type   = htmlspecialchars($item['type']   ?? '', ENT_QUOTES, 'UTF-8');
                $consig = htmlspecialchars($item['consiga'] ?? '', ENT_QUOTES, 'UTF-8');
                $h .= '<li>';
                if ($type) { $h .= '<div class="item-meta">' . $type . '</div>'; }
                $h .= nl2br($consig);
                $alts = $item['alternativas'] ?? [];
                if (!empty($alts)) {
                    $h .= '<ul class="alts">';
                    foreach ($alts as $alt) {
                        $h .= '<li>' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '</li>';
                    }
                    $h .= '</ul>';
                }
                $h .= '</li>';
            }
            $h .= '</ol>';
        }

        // Justification
        if (!empty($justif)) {
            $h .= '<div class="justification"><strong>Justificación pedagógica:</strong> ';
            $h .= htmlspecialchars($justif, ENT_QUOTES, 'UTF-8') . '</div>';
        }

        // Rubric (if present and structured)
        $criteria = $rubric_data['criteria'] ?? ($rubric_data['items'] ?? []);
        if (!empty($criteria)) {
            $h .= '<h2>📊 Criterios de Evaluación</h2>';
            $h .= '<table class="rubric-table"><thead><tr>';
            $h .= '<th>Criterio</th><th>Descripción</th>';
            if (!empty($criteria[0]['levels'])) { $h .= '<th>Niveles</th>'; }
            $h .= '</tr></thead><tbody>';
            foreach ($criteria as $crit) {
                $h .= '<tr>';
                $h .= '<td><strong>' . htmlspecialchars($crit['name'] ?? $crit['criterion'] ?? '', ENT_QUOTES, 'UTF-8') . '</strong></td>';
                $h .= '<td>' . htmlspecialchars($crit['description'] ?? $crit['consiga'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
                if (!empty($crit['levels'])) {
                    $levels_str = implode(' / ', array_map(function($l) {
                        return htmlspecialchars(is_array($l) ? ($l['label'] ?? $l['name'] ?? '') : $l, ENT_QUOTES, 'UTF-8');
                    }, $crit['levels']));
                    $h .= '<td>' . $levels_str . '</td>';
                }
                $h .= '</tr>';
            }
            $h .= '</tbody></table>';
        }

        $h .= '<div class="footer">AreteIA · Diseño de instrumentos de evaluación · ' . $date_str . '</div>';
        $h .= '</body></html>';

        // ── Try Moodle TCPDF for real PDF; fall back to HTML download ──
        $safe_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $instrument);
        $libpdf    = $CFG->libdir . '/pdflib.php';

        if (file_exists($libpdf)) {
            require_once($libpdf);
            try {
                $doc = new \pdf();
                $doc->setPrintHeader(false);
                $doc->setPrintFooter(false);
                $doc->SetMargins(15, 15, 15);
                $doc->AddPage();
                $doc->writeHTML($h, true, false, true, false, '');
                $doc->Output('AreteIA_' . $safe_name . '.pdf', 'D');
                die();
            } catch (\Throwable $e) {
                // Fall through to HTML download
            }
        }

        // Fallback: serve as printable HTML
        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Disposition: attachment; filename="AreteIA_' . $safe_name . '.html"');
        echo $h;
        die();
    }
}
