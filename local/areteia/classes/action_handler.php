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
                require_sesskey();
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
                self::handle_export_pdf($course_id, 'student');
                return true;

            case 'export_pdf_teacher':
                self::handle_export_pdf($course_id, 'teacher');
                return true;

            case 'export_docx':
                self::handle_export_docx($course_id, 'student');
                return true;

            case 'export_docx_teacher':
                self::handle_export_docx($course_id, 'teacher');
                return true;

            case 'adjust_item':
                require_sesskey();
                self::handle_adjust_item($course_id, $base_url, $is_ajax);
                return true;

            case 'save_item':
                require_sesskey();
                self::handle_save_item($course_id, $base_url);
                return true;

            case 'export_correction_pdf':
                self::handle_export_correction_pdf($course_id);
                return true;

            case 'export_correction_docx':
                self::handle_export_correction_docx($course_id);
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
        activity_logger::timed($course_id, 'sync', function() use ($course_id) {
            // Extract files to sync dir + get summary
            data_provider::get_course_files($course_id, true);
            $summary = data_provider::get_course_summary($course_id);

            // POST /sync
            rag_client::sync($summary);
        });

        // Always return to the Library tab (Step 1)
        $redir = new \moodle_url($base_url, ['step' => 1, 'use_moodle' => 1, 'action' => 'lib']);
        if ($is_ajax) {
            // For AJAX: return JSON with redirect URL, avoid Moodle's redirect() which mutates session
            // Must clear output buffer BEFORE echoing, otherwise ob_end_clean() discards our JSON.
            if (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['redirect' => $redir->out(false)]);
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

        $selected_files_raw = optional_param('selected_files', '', PARAM_RAW);
        $selected_files = [];
        if (!empty($selected_files_raw)) {
            $selected_files = json_decode($selected_files_raw, true);
        }

        $res_data = activity_logger::timed($course_id, 'ingest', function() use ($course_id, $selected_files_raw, $CFG) {
            // 1. Force a clean physical extraction of ALL allowed course files to disk.
            data_provider::get_course_files($course_id, true);

            // 2. Filter: only keep user-selected files on disk before calling Python.
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

            return rag_client::ingest($course_id, $selected_files ?? [], $base_sync_dir);
        }, [
            'detail' => [
                'files_count' => is_array($selected_files) ? count($selected_files) : 0,
            ]
        ]);

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
        activity_logger::timed($course_id, 'delete_rag', function() use ($course_id) {
            rag_client::delete($course_id);
            data_provider::delete_sync_dir($course_id);
        });
        
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

        $instrument = session_manager::get('instrument', '');
        $moduleinfo = activity_logger::timed($course_id, 'inject_assign', function() use ($course_id, $inst_name, $final_desc) {
            return \local_areteia\data_provider::create_assign_activity($course_id, $inst_name, $final_desc);
        }, [
            'instrument' => $instrument,
            'instrument_type' => 'assign',
            'detail' => ['mode' => 'legacy_export']
        ]);

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
            $instrument = session_manager::get('instrument', '');
            $result = activity_logger::timed($course_id, 'inject_quiz', function() use ($course_id, $section_num, $questions, $max_grade) {
                // Se pasa el max_grade además de name (si se desea uno por defecto se pasa null o string custom)
                return \local_areteia\data_provider::create_quiz_activity($course_id, $section_num, $questions, 'Cuestionario AreteIA', $max_grade);
            }, [
                'instrument' => $instrument,
                'instrument_type' => 'quiz',
                'detail' => [
                    'section_num' => $section_num,
                    'questions_count' => count($questions),
                    'max_grade' => $max_grade
                ]
            ]);
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
        $rubric_content = session_manager::get('rubric_content', '');

        // Prefer filtered selection (items chosen in step 5) over full unfiltered content
        $inst_content = session_manager::get('inst_content_filtered', '') ?: session_manager::get('inst_content', '');

        // Build a rich description from the instrument items
        $description = self::build_activity_description($inst_content, $rubric_content);

        if (!$inst_name || $inst_name === ' - AreteIA') {
            $inst_name = 'Evaluación AreteIA';
        }

        try {
            $instrument = session_manager::get('instrument', '');
            $moduleinfo = activity_logger::timed($course_id, 'inject_assign', function() use ($course_id, $inst_name, $description, $section_num) {
                return data_provider::create_assign_activity($course_id, $inst_name, $description, $section_num);
            }, [
                'instrument' => $instrument,
                'instrument_type' => 'assign',
                'detail' => [
                    'section_num' => $section_num
                ]
            ]);
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
        $rubric_content = session_manager::get('rubric_content', '');

        // Prefer filtered selection (items chosen in step 5) over full unfiltered content
        $inst_content = session_manager::get('inst_content_filtered', '') ?: session_manager::get('inst_content', '');

        $description = self::build_activity_description($inst_content, $rubric_content);

        if (!$inst_name || $inst_name === ' - AreteIA') {
            $inst_name = 'Debate AreteIA';
        }

        try {
            $instrument = session_manager::get('instrument', '');
            $moduleinfo = activity_logger::timed($course_id, 'inject_forum', function() use ($course_id, $inst_name, $description, $section_num) {
                return data_provider::create_forum_activity($course_id, $inst_name, $description, $section_num);
            }, [
                'instrument' => $instrument,
                'instrument_type' => 'forum',
                'detail' => [
                    'section_num' => $section_num
                ]
            ]);
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

            // Scenario/case narrative must appear before items (Estudio de caso, Debate, Rol, etc.)
            if (!empty($data['scenario'])) {
                $parts[] = '';
                $parts[] = '### 📖 Escenario / Contexto';
                $parts[] = $data['scenario'];
                $parts[] = '';
                $parts[] = '---';
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

            /* Justificación Pedagógica - temporalmente oculta
            if (!empty($data['justification'])) {
                $parts[] = '---';
                $parts[] = '**Justificación Pedagógica:** ' . $data['justification'];
            }
            */
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
     * Resolve export data from session: prefer final_selection_json (quiz instruments),
     * fall back to inst_content (non-quiz: case study, essay, etc.).
     * Returns [items, title, scenario, justification, objectives_data].
     */
    private static function resolve_export_data(): array {
        $instrument    = session_manager::get('instrument', 'Evaluación');
        $final_raw     = session_manager::get('final_selection_json', '');
        $final_decoded = $final_raw ? json_decode($final_raw, true) : null;

        if (!empty($final_decoded['items'])) {
            // Quiz / TAREA CON ENTREGA path — items already in generator format
            $items          = $final_decoded['items'];
            $title          = $final_decoded['title']         ?? $instrument;
            $scenario       = $final_decoded['scenario']      ?? '';
            $justification  = $final_decoded['justification'] ?? '';
            $objectives_data = json_decode(session_manager::get('d2_json', ''), true) ?: [];
        } else {
            // Non-quiz path — build items from inst_content
            $inst_raw  = session_manager::get('inst_content', '');
            $inst_data = $inst_raw ? (json_decode($inst_raw, true) ?? []) : [];

            $raw_items = $inst_data['items'] ?? [];
            $items = [];
            foreach ($raw_items as $item) {
                $items[] = [
                    'type'        => $item['type']       ?? 'essay',
                    'text'        => $item['consiga']    ?? $item['text'] ?? '',
                    'points'      => $item['points']     ?? 0,
                    'difficulty'  => $item['difficulty'] ?? '',
                    'objectives'  => $item['objectives'] ?? [],
                ];
            }

            $title           = $inst_data['title']         ?? $instrument;
            $scenario        = $inst_data['scenario']      ?? '';
            $justification   = $inst_data['justification'] ?? '';
            $objectives_data = json_decode(session_manager::get('d2_json', ''), true) ?: [];
        }

        return [$items, $title, $scenario, $justification, $objectives_data];
    }

    /**
     * Export instrument as a PDF download (student or teacher version).
     * No sesskey required — read-only download.
     */
    private static function handle_export_pdf(int $course_id, string $version = 'student'): void {
        global $CFG, $PAGE;

        [$items, $title, $scenario, $justification, $objectives_data] = self::resolve_export_data();

        $course_name = $PAGE->course->fullname ?? 'Curso';
        $suffix      = ($version === 'teacher') ? 'Docente' : 'Estudiante';
        $filename    = \clean_filename($title . ' - ' . $suffix) . '.pdf';

        $tcpdf_path = $CFG->libdir . '/tcpdf/tcpdf.php';
        if (!file_exists($tcpdf_path)) {
            $tcpdf_path = $CFG->libdir . '/pdflib.php';
        }

        if (file_exists($tcpdf_path)) {
            require_once(__DIR__ . '/pdf_generator.php');
            require_once($tcpdf_path);
            try {
                $pdf_content = activity_logger::timed($course_id, 'export_pdf', function() use ($version, $items, $title, $course_name, $justification, $objectives_data, $scenario) {
                    if ($version === 'teacher') {
                        return \local_areteia\pdf_generator::generate_teacher_pdf(
                            $items, $title, $course_name, $justification, $objectives_data, $scenario
                        );
                    } else {
                        return \local_areteia\pdf_generator::generate_student_pdf(
                            $items, $title, $course_name, $scenario
                        );
                    }
                }, [
                    'instrument' => $title,
                    'detail' => ['version' => $version]
                ]);
                while (ob_get_level() > 0) { ob_end_clean(); }
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Cache-Control: private, max-age=0, must-revalidate');
                echo $pdf_content;
                exit;
            } catch (\Throwable $e) {
                // Fall through to HTML fallback
            }
        }

        // Fallback: serve printable HTML (student content only)
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $title);
        $h  = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">';
        $h .= '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
        $h .= '<style>body{font-family:Georgia,serif;font-size:12pt;max-width:820px;margin:0 auto;padding:30px;}';
        $h .= 'h1{font-size:18pt;border-bottom:2px solid #6c63ff;padding-bottom:8px;color:#2d2d6d;}';
        $h .= '.scenario{background:#fffbea;border-left:5px solid #f59e0b;padding:14px 18px;margin:16px 0;}';
        $h .= 'ol li{margin-bottom:12px;}</style></head><body>';
        $h .= '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
        if (!empty($scenario)) {
            $h .= '<div class="scenario">' . nl2br(htmlspecialchars($scenario, ENT_QUOTES, 'UTF-8')) . '</div>';
        }
        $h .= '<ol>';
        foreach ($items as $item) {
            $h .= '<li>' . nl2br(htmlspecialchars($item['text'] ?? $item['consiga'] ?? '', ENT_QUOTES, 'UTF-8')) . '</li>';
        }
        $h .= '</ol>';
        /* Justificación Pedagógica en HTML fallback - temporalmente oculta
        if ($version === 'teacher' && !empty($justification)) {
            $h .= '<hr><p><em>' . htmlspecialchars($justification, ENT_QUOTES, 'UTF-8') . '</em></p>';
        }
        */
        $h .= '</body></html>';

        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Disposition: attachment; filename="AreteIA_' . $safe . '_' . $suffix . '.html"');
        echo $h;
        exit;
    }

    /**
     * Export instrument as a DOCX download (student or teacher version).
     * No sesskey required — read-only download.
     */
    private static function handle_export_docx(int $course_id, string $version = 'student'): void {
        global $PAGE;

        [$items, $title, $scenario, $justification, $objectives_data] = self::resolve_export_data();

        $course_name = $PAGE->course->fullname ?? 'Curso';
        $suffix      = ($version === 'teacher') ? 'Docente' : 'Estudiante';
        $filename    = \clean_filename($title . ' - ' . $suffix) . '.docx';

        require_once(__DIR__ . '/docx_generator.php');

        $docx_content = activity_logger::timed($course_id, 'export_docx', function() use ($version, $items, $title, $course_name, $justification, $objectives_data, $scenario) {
            if ($version === 'teacher') {
                return \local_areteia\docx_generator::generate_teacher_docx(
                    $items, $title, $course_name, $justification, $objectives_data, $scenario
                );
            } else {
                return \local_areteia\docx_generator::generate_student_docx(
                    $items, $title, $course_name, $scenario
                );
            }
        }, [
            'instrument' => $title,
            'detail' => ['version' => $version]
        ]);

        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        echo $docx_content;
        exit;
    }

    // ------------------------------------------------------------------
    // Step 5.1 — AI-assisted single-item adjustment
    // ------------------------------------------------------------------

    /**
     * Adjust a single item via the Python step-5.1 endpoint.
     * Reads item_index + feedback from GET params, patches inst_content in session.
     */
    private static function handle_adjust_item(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        global $PAGE;

        $item_index  = optional_param('item_index', -1, PARAM_INT);
        $instruction = optional_param('feedback',   '',  PARAM_TEXT);
        // Strip "[Ítem N] " prefix added by JS
        $instruction = preg_replace('/^\[Ítem \d+\]\s*/u', '', $instruction);

        $redir = new \moodle_url($base_url, ['step' => 5, 'id' => $course_id]);

        if ($item_index < 0 || trim($instruction) === '') {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['redirect' => $redir->out(false)]);
                exit;
            }
            redirect($redir);
            return;
        }

        $raw_content = session_manager::get('inst_content', '');
        $data = $raw_content ? json_decode($raw_content, true) : null;

        if (!$data || !isset($data['items'][$item_index])) {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['redirect' => $redir->out(false)]);
                exit;
            }
            redirect($redir);
            return;
        }

        $res = activity_logger::timed($course_id, 'adjust_item', function() use ($course_id, $PAGE, $data, $item_index, $instruction) {
            return rag_client::generate([
                'course_id'          => $course_id,
                'course_title'       => $PAGE->course->fullname,
                'step'               => 5.1,
                'item'               => $data['items'][$item_index],
                'feedback'           => $instruction,
                'objective_json'     => session_manager::get('d2_json', ''),
                'chosen_instrument'  => session_manager::get('instrument', ''),
            ]);
        }, [
            'instrument' => session_manager::get('instrument', ''),
            'detail' => [
                'item_index' => $item_index,
                'feedback' => $instruction
            ]
        ]);

        if ($res && isset($res->status) && $res->status === 'success' && !empty($res->output)) {
            // Convert stdClass to assoc array and patch the item
            $new_item = json_decode(json_encode($res->output), true);
            if (is_array($new_item)) {
                $data['items'][$item_index] = $new_item;
                session_manager::set('inst_content', json_encode($data));
            }
        }

        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['redirect' => $redir->out(false)]);
            exit;
        }
        redirect($redir);
    }

    /**
     * Save manually-edited item fields (consiga, difficulty, points) into session.
     */
    private static function handle_save_item(int $course_id, \moodle_url $base_url): void {
        $item_index      = optional_param('item_index',           -1,   PARAM_INT);
        $new_consiga     = optional_param('item_consiga',         '',   PARAM_TEXT);
        $difficulty      = optional_param('item_difficulty',      '',   PARAM_TEXT);
        $points          = optional_param('item_points',          null, PARAM_FLOAT);
        $correct_index   = optional_param('item_correct_index',   null, PARAM_INT);
        $correct_boolean = optional_param('item_correct_boolean', null, PARAM_TEXT);

        if ($item_index >= 0) {
            $raw_content = session_manager::get('inst_content', '');
            $data = $raw_content ? json_decode($raw_content, true) : null;

            if ($data && isset($data['items'][$item_index])) {
                if (trim($new_consiga) !== '') {
                    $data['items'][$item_index]['consiga'] = $new_consiga;
                }
                $allowed_difficulties = ['Fácil', 'Media', 'Difícil'];
                if (in_array($difficulty, $allowed_difficulties, true)) {
                    $data['items'][$item_index]['difficulty'] = $difficulty;
                }
                if ($points !== null && $points > 0) {
                    $data['items'][$item_index]['points'] = $points;
                }
                if ($correct_index !== null && $correct_index >= 0) {
                    $data['items'][$item_index]['correct_index'] = (int)$correct_index;
                }
                if ($correct_boolean === '1' || $correct_boolean === '0') {
                    $data['items'][$item_index]['correct_boolean'] = ($correct_boolean === '1');
                }
                session_manager::set('inst_content', json_encode($data));

                activity_logger::log($course_id, 'save_item', [
                    'instrument' => session_manager::get('instrument', ''),
                    'detail' => [
                        'item_index' => $item_index,
                        'difficulty' => $difficulty,
                        'points' => $points,
                    ]
                ]);
            }
        }

        redirect(new \moodle_url('/local/areteia/index.php', ['id' => $course_id, 'step' => 5]));
    }

    /**
     * Export the correction instrument (apoyo a la calificación) as a PDF download.
     * Falls back to HTML if TCPDF is unavailable.
     */
    private static function handle_export_correction_pdf(int $course_id): void {
        global $CFG, $PAGE;

        $redir = new \moodle_url('/local/areteia/index.php', ['id' => $course_id, 'action' => 'crit', 'step' => 6]);

        $correction         = session_manager::get('correction_instrument', '');
        $correction_content = session_manager::get('correction_content', '');
        $instrument         = session_manager::get('instrument', '');

        if (empty($correction_content)) { redirect($redir); }
        $data = json_decode($correction_content, true);
        if (!is_array($data)) { redirect($redir); }

        $labels = [
            'clave_correccion'  => 'Clave de Corrección',
            'lista_cotejo'      => 'Lista de Cotejo',
            'escala_valoracion' => 'Escala de Valoración',
            'rubrica'           => 'Rúbrica',
        ];
        $label    = $labels[$correction] ?? $correction;
        $course_name = $PAGE->course->fullname ?? '';
        $filename = \clean_filename($label . ' - ' . $instrument) . '.pdf';

        $tcpdf_path = $CFG->libdir . '/tcpdf/tcpdf.php';
        if (!file_exists($tcpdf_path)) {
            $tcpdf_path = $CFG->libdir . '/pdflib.php';
        }

        if (file_exists($tcpdf_path)) {
            require_once(__DIR__ . '/pdf_generator.php');
            require_once($tcpdf_path);
            try {
                $pdf_content = \local_areteia\pdf_generator::generate_correction_pdf(
                    $correction, $data, $instrument, $course_name
                );
                while (ob_get_level() > 0) { ob_end_clean(); }
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Cache-Control: private, max-age=0, must-revalidate');
                echo $pdf_content;
                exit;
            } catch (\Throwable $e) {
                // Fall through to HTML fallback
            }
        }

        // HTML fallback (printable)
        $title_safe = htmlspecialchars($data['title'] ?? ($label . ' — ' . $instrument), ENT_QUOTES, 'UTF-8');
        $safe       = preg_replace('/[^a-zA-Z0-9_-]/', '_', $label . '_' . $instrument);
        $h  = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>' . $title_safe . '</title>';
        $h .= '<style>body{font-family:Arial,sans-serif;font-size:12pt;max-width:900px;margin:0 auto;padding:30px;}';
        $h .= 'h1{font-size:16pt;border-bottom:3px solid #6c63ff;padding-bottom:8px;color:#2d2d6d;}';
        $h .= 'table{width:100%;border-collapse:collapse;}th{background:#6c63ff;color:#fff;padding:8px;text-align:left;}';
        $h .= 'td{padding:7px 9px;border:1px solid #ddd;font-size:10pt;vertical-align:top;}';
        $h .= 'tr:nth-child(even){background:#f9f9f9;}</style></head><body>';
        $h .= '<h1>' . $title_safe . '</h1>';
        // Minimal fallback: just dump JSON as a table
        $h .= '<pre style="font-size:9pt;">' . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . '</pre>';
        $h .= '</body></html>';
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Disposition: attachment; filename="AreteIA_' . $safe . '.html"');
        echo $h;
        exit;
    }

    /**
     * Export the correction instrument (apoyo a la calificación) as a DOCX download.
     */
    private static function handle_export_correction_docx(int $course_id): void {
        global $PAGE;

        $redir = new \moodle_url('/local/areteia/index.php', ['id' => $course_id, 'action' => 'crit', 'step' => 6]);

        $correction         = session_manager::get('correction_instrument', '');
        $correction_content = session_manager::get('correction_content', '');
        $instrument         = session_manager::get('instrument', '');

        if (empty($correction_content)) { redirect($redir); }
        $data = json_decode($correction_content, true);
        if (!is_array($data)) { redirect($redir); }

        $labels = [
            'clave_correccion'  => 'Clave de Corrección',
            'lista_cotejo'      => 'Lista de Cotejo',
            'escala_valoracion' => 'Escala de Valoración',
            'rubrica'           => 'Rúbrica',
        ];
        $label    = $labels[$correction] ?? $correction;
        $course_name = $PAGE->course->fullname ?? '';
        $filename = \clean_filename($label . ' - ' . $instrument) . '.docx';

        require_once(__DIR__ . '/docx_generator.php');
        $docx_content = \local_areteia\docx_generator::generate_correction_docx(
            $correction, $data, $instrument, $course_name
        );
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        echo $docx_content;
        exit;
    }
}
