<?php
namespace local_areteia;

defined('MOODLE_INTERNAL') || die();

/**
 * PDF Generator for evaluation instruments
 * Generates two versions: Student (clean questionnaire) and Teacher (with answers/objectives)
 */
class pdf_generator {

    /**
     * Generate student PDF (clean questionnaire without answers)
     * 
     * @param array $items Filtered items from final_selection_json
     * @param string $title Evaluation title
     * @param string $course_name Course name
     * @return string PDF binary content
     */
    public static function generate_student_pdf(array $items, string $title, string $course_name, string $scenario = ''): string {
        global $CFG;
        require_once($CFG->libdir . '/tcpdf/tcpdf.php');
        
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Set document properties
        $pdf->SetCreator('AreteIA');
        $pdf->SetAuthor('AreteIA');
        $pdf->SetTitle($title);
        $pdf->SetSubject('Cuestionario Estudiante');
        
        // Set margins
        $margin = 30;
        $pdf->SetMargins($margin, $margin, $margin);
        $pdf->SetTopMargin($margin);
        $pdf->SetAutoPageBreak(true, $margin);
        $pdf->SetHeaderMargin(0);
        $pdf->SetFooterMargin(0);
        
        // Add a page
        $pdf->AddPage();
        $pdf->SetY($margin);
        $pdf->SetX($margin);
        $titlewidth = $pdf->getPageWidth() - $pdf->getMargins()['left'] - $pdf->getMargins()['right'];
        
        // Set title header
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->MultiCell($titlewidth, 7, $title, 0, 'C', false, 1, '', '', true);
        $pdf->Ln(5);
        
        // Total points
        $total_points = 0;
        foreach ($items as $item) {
            $total_points += (float)($item['points'] ?? 0);
        }
        
        $pdf->SetFont('helvetica', '', 11);
        $pdf->Cell(0, 8, 'Puntos Totales: ' . round($total_points, 2), 0, 1, 'R');
        $pdf->Ln(5);
        
        // Instrucciones
        $pdf->SetFont('helvetica', 'I', 10);
        $pdf->MultiCell(0, 5, 'Responde todas las preguntas con cuidado. Muestra todo tu procedimiento cuando corresponda.', 0, 'L');
        $pdf->Ln(8);

        // Scenario / context (case study, debate, etc.)
        if (!empty($scenario)) {
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(0, 6, 'Escenario / Contexto', 0, 1);
            $pdf->SetFont('helvetica', '', 10);
            $pdf->MultiCell(0, 5, $scenario, 0, 'L');
            $pdf->Ln(6);
        }

        // Items
        $pdf->SetFont('helvetica', '', 10);
        foreach ($items as $index => $item) {
            self::render_student_item($pdf, $index + 1, $item);
            $pdf->Ln(3);
        }
        
        // Return PDF as string
        return $pdf->Output('', 'S');
    }

    /**
     * Generate teacher PDF (with answers, objectives, and justification)
     * 
     * @param array $items Filtered items from final_selection_json
     * @param string $title Evaluation title
     * @param string $course_name Course name
     * @param string $justification Pedagogical justification
     * @param array $objectives_data Objectives with Bloom levels (from d2_json)
     * @return string PDF binary content
     */
    public static function generate_teacher_pdf(array $items, string $title, string $course_name, string $justification, array $objectives_data, string $scenario = ''): string {
        global $CFG;
        require_once($CFG->libdir . '/tcpdf/tcpdf.php');
        
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Set document properties
        $pdf->SetCreator('AreteIA');
        $pdf->SetAuthor('AreteIA');
        $pdf->SetTitle($title . ' - Versión Docente');
        $pdf->SetSubject('Clave de respuestas');
        
        // Set margins
        $margin = 30;
        $pdf->SetMargins($margin, $margin, $margin);
        $pdf->SetTopMargin($margin);
        $pdf->SetAutoPageBreak(true, $margin);
        $pdf->SetHeaderMargin(0);
        $pdf->SetFooterMargin(0);
        
        // Add a page
        $pdf->AddPage();
        $pdf->SetY($margin);
        $pdf->SetX($margin);
        $titlewidth = $pdf->getPageWidth() - $pdf->getMargins()['left'] - $pdf->getMargins()['right'];
        
        // Set title header
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->MultiCell($titlewidth, 7, $title . ' - VERSIÓN DOCENTE', 0, 'C', false, 1, '', '', true);
        $pdf->Ln(5);
        
        // Total points
        $total_points = 0;
        foreach ($items as $item) {
            $total_points += (float)($item['points'] ?? 0);
        }
        
        $pdf->SetFont('helvetica', '', 11);
        $pdf->Cell(0, 8, 'Puntos Totales: ' . round($total_points, 2), 0, 1, 'R');
        $pdf->Ln(3);
        $pdf->Cell(0, 8, 'Total de ítems: ' . count($items), 0, 1, 'R');
        $pdf->Ln(5);
        
        /* Justificación pedagógica - temporalmente oculta
        if (!empty($justification)) {
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(0, 6, 'Justificación pedagógica:', 0, 1);
            
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->MultiCell(0, 5, $justification, 0, 'L');
            $pdf->Ln(5);
        }
        */
        
        // Scenario / context
        if (!empty($scenario)) {
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(0, 6, 'Escenario / Contexto', 0, 1);
            $pdf->SetFont('helvetica', '', 10);
            $pdf->MultiCell(0, 5, $scenario, 0, 'L');
            $pdf->Ln(6);
        }

        // Objectives summary
        if (!empty($objectives_data)) {
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(0, 6, 'Objetivos de aprendizaje:', 0, 1);
            
            $pdf->SetFont('helvetica', '', 9);
            foreach ($objectives_data as $idx => $obj) {
                $bloom = $obj['bloom'] ?? '';
                $text = $obj['text'] ?? '';
                $prefix = $bloom ? "{$bloom}: " : '';
                $pdf->MultiCell(0, 5, ($idx + 1) . ". {$prefix}{$text}", 0, 'L');
            }
            $pdf->Ln(5);
        }
        
        // Build objective map for lookup
        $objective_map = [];
        foreach ($objectives_data as $idx => $obj) {
            if (!empty($obj['text'])) {
                $ref_key = 'Obj ' . ($idx + 1);
                $objective_map[$ref_key] = $obj;
            }
        }
        
        // Items with answers
        $pdf->SetFont('helvetica', '', 10);
        foreach ($items as $index => $item) {
            self::render_teacher_item($pdf, $index + 1, $item, $objective_map);
            $pdf->Ln(5);
        }
        
        // Return PDF as string
        return $pdf->Output('', 'S');
    }

    /**
     * Render a single item for student PDF
     */
    private static function render_student_item(\TCPDF $pdf, int $item_num, array $item): void {
        $pdf->SetFont('helvetica', 'B', 10);
        $type = $item['type'] ?? 'essay';
        $difficulty = $item['difficulty'] ?? 'Medium';
        $points = round((float)($item['points'] ?? 0), 2);
        
        $pdf->Cell(0, 6, "Pregunta {$item_num} - {$points} pts", 0, 1);
        
        $pdf->SetFont('helvetica', '', 10);
        $text = $item['text'] ?? $item['consiga'] ?? '';
        if ($type === 'multianswer' || strpos($type, 'cloze') !== false || strpos($type, 'lacunar') !== false) {
            $text = self::render_cloze_text($text, $item['cloze_answer'] ?? $item['correct'] ?? $item['short_answer'] ?? '');
        }
        $pdf->MultiCell(0, 5, $text, 0, 'L');
        $pdf->Ln(2);
        
        // Options for multiple choice
        if (in_array($type, ['multichoice', 'selección', 'múltiple'])) {
            $pdf->SetFont('helvetica', '', 9);
            $options = $item['options'] ?? [];
            foreach ($options as $opt_idx => $opt) {
                $letter = chr(65 + $opt_idx); // A, B, C, D
                $pdf->Cell(10, 5, "$letter)", 0, 0);
                $pdf->MultiCell(0, 5, $opt, 0, 'L');
            }
        } elseif (in_array($type, ['truefalse', 'verdadero'])) {
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Cell(10, 5, "T)", 0, 0);
            $pdf->Cell(0, 5, 'Verdadero', 0, 1);
            $pdf->Cell(10, 5, "F)", 0, 0);
            $pdf->Cell(0, 5, 'Falso', 0, 1);
        } elseif ($type === 'match' || strpos($type, 'emparejamiento') !== false || strpos($type, 'orden') !== false) {
            $pairs = $item['pairs'] ?? [];
            if (!empty($pairs)) {
                self::render_match_table($pdf, $pairs);
            }
        }
        
        $pdf->Ln(5);
        
        // Answer space
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->SetLineWidth(0.1);
        for ($i = 0; $i < 4; $i++) {
            $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
            $pdf->Ln(5);
        }
    }

    /**
     * Render a single item for teacher PDF with answers
     */
    private static function render_teacher_item(\TCPDF $pdf, int $item_num, array $item, array $objective_map): void {
        $pdf->SetFont('helvetica', 'B', 10);
        $type = $item['type'] ?? 'essay';
        $difficulty = $item['difficulty'] ?? 'Medium';
        $points = round((float)($item['points'] ?? 0), 2);
        
        $pdf->Cell(0, 6, "Pregunta {$item_num} - {$points} pts", 0, 1);
        
        $pdf->SetFont('helvetica', '', 10);
        $text = $item['text'] ?? $item['consiga'] ?? '';
        $pdf->MultiCell(0, 5, $text, 0, 'L');
        $pdf->Ln(2);
        
        // Options for multiple choice
        if (in_array($type, ['multichoice', 'selección', 'múltiple'])) {
            $pdf->SetFont('helvetica', '', 9);
            $options = $item['options'] ?? [];
            foreach ($options as $opt_idx => $opt) {
                $letter = chr(65 + $opt_idx); // A, B, C, D
                $is_correct = isset($item['correct']) && $item['correct'] == $opt_idx;
                $marker = $is_correct ? '✓ ' : '  ';
                $pdf->SetFont('helvetica', $is_correct ? 'B' : '', 9);
                $pdf->Cell(10, 5, "$letter)", 0, 0);
                $pdf->MultiCell(0, 5, $marker . $opt, 0, 'L');
            }
        } elseif (in_array($type, ['truefalse', 'verdadero'])) {
            $pdf->SetFont('helvetica', '', 9);
            $correct = $item['correct'] ?? true;
            $t_correct = $correct ? '✓' : '';
            $f_correct = !$correct ? '✓' : '';
            $pdf->Cell(10, 5, "T)", 0, 0);
            $pdf->Cell(0, 5, "$t_correct Verdadero", 0, 1);
            $pdf->Cell(10, 5, "F)", 0, 0);
            $pdf->Cell(0, 5, "$f_correct Falso", 0, 1);
        } elseif ($type === 'match' || strpos($type, 'emparejamiento') !== false || strpos($type, 'orden') !== false) {
            $pairs = $item['pairs'] ?? [];
            if (!empty($pairs)) {
                self::render_match_table($pdf, $pairs);
            }
        } elseif (in_array($type, ['shortanswer', 'breve', 'clásica'])) {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, 'Respuesta esperada:', 0, 1);
            $pdf->SetFont('helvetica', '', 9);
            $correct = $item['correct'] ?? '';
            $pdf->MultiCell(0, 5, $correct, 0, 'L');
        } elseif ($type === 'multianswer' || strpos($type, 'cloze') !== false || strpos($type, 'lacunar') !== false) {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, 'Respuesta esperada:', 0, 1);
            $pdf->SetFont('helvetica', '', 9);
            $correct = $item['cloze_answer'] ?? $item['correct'] ?? $item['short_answer'] ?? '';
            $pdf->MultiCell(0, 5, $correct, 0, 'L');
        } elseif (in_array($type, ['numerical', 'numérica'])) {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, 'Respuesta esperada:', 0, 1);
            $pdf->SetFont('helvetica', '', 9);
            $correct = $item['correct'] ?? 0;
            $pdf->Cell(0, 5, (string)$correct, 0, 1);
        }
        
        $pdf->Ln(2);
        
        // Objectives with Bloom levels
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 5, 'Objetivos de aprendizaje:', 0, 1);
        $pdf->SetFont('helvetica', '', 9);
        
        if (!empty($item['objectives'])) {
            foreach ($item['objectives'] as $obj_ref) {
                if (isset($objective_map[$obj_ref])) {
                    $obj = $objective_map[$obj_ref];
                    $bloom = $obj['bloom'] ?? '';
                    $text = $obj['text'] ?? $obj_ref;
                    $prefix = $bloom ? "{$bloom}: " : '';
                    $pdf->MultiCell(0, 5, "• {$prefix}{$text}", 0, 'L');
                } else {
                    $pdf->MultiCell(0, 5, "• {$obj_ref}", 0, 'L');
                }
            }
        }
        
        $pdf->SetDrawColor(100, 100, 100);
        $pdf->SetLineWidth(0.3);
        $pdf->Line(15, $pdf->GetY() + 2, 195, $pdf->GetY() + 2);
    }

    /**
     * Render a matching table inside the PDF for match items.
     */
    private static function render_match_table(\TCPDF $pdf, array $pairs): void {
        $pdf->SetFont('helvetica', '', 9);
        $html = '<table cellpadding="4" cellspacing="0" border="1" style="border-collapse:collapse; width:100%;">';
        $html .= '<tr style="background-color:#f6f6f6;">';
        $html .= '<th style="padding:6px; border:1px solid #ccc; text-align:left;">Premisa</th>';
        $html .= '<th style="padding:6px; border:1px solid #ccc; text-align:left;">Respuesta</th>';
        $html .= '</tr>';
        foreach ($pairs as $pair_idx => $pair) {
            $left = htmlspecialchars($pair['premise'] ?? $pair['premisa'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $right = htmlspecialchars($pair['answer'] ?? $pair['respuesta'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<tr>';
            $html .= '<td style="padding:6px; border:1px solid #ccc; vertical-align:top;">' . ($pair_idx + 1) . '. ' . $left . '</td>';
            $html .= '<td style="padding:6px; border:1px solid #ccc; vertical-align:top;">' . chr(65 + $pair_idx) . '. ' . $right . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
        $pdf->writeHTMLCell(0, 0, '', '', $html, 0, 1, false, true, 'L', true);
    }

    private static function render_cloze_text(string $text, string $answer = ''): string {
        $rendered = trim($text);
        $answer = trim($answer);

        if ($rendered === '' || $answer === '') {
            return $rendered;
        }

        if (!preg_match('/_{3,}|\[[^\]]*\]/u', $rendered) && stripos($rendered, $answer) !== false) {
            $rendered = preg_replace('/' . preg_quote($answer, '/') . '/iu', '__________', $rendered, 1);
            return $rendered;
        }

        $rendered = preg_replace('/_{3,}/u', '__________', $rendered);
        $rendered = preg_replace('/\[[^\]]*\]/u', '__________', $rendered);

        if ($rendered === trim($text)) {
            $rendered .= ' __________';
        }

        return $rendered;
    }

    /**
     * Generate a teacher-facing PDF for the correction instrument (apoyo a la calificación).
     *
     * @param string $correction_type  One of: clave_correccion, lista_cotejo, escala_valoracion, rubrica
     * @param array  $data             Decoded correction_content from session
     * @param string $instrument       Instrument name (e.g. "Prueba Mixta")
     * @param string $course_name      Course name
     * @return string PDF binary
     */
    public static function generate_correction_pdf(string $correction_type, array $data, string $instrument, string $course_name): string {
        global $CFG;
        require_once($CFG->libdir . '/tcpdf/tcpdf.php');

        $labels = [
            'clave_correccion'  => 'Clave de Corrección',
            'lista_cotejo'      => 'Lista de Cotejo',
            'escala_valoracion' => 'Escala de Valoración',
            'rubrica'           => 'Rúbrica',
        ];
        $label = $labels[$correction_type] ?? $correction_type;
        $title = $data['title'] ?? ($label . ' — ' . $instrument);

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('AreteIA');
        $pdf->SetAuthor('AreteIA');
        $pdf->SetTitle($title);
        $pdf->SetSubject('Apoyo a la Calificación');
        $margin = 25;
        $pdf->SetMargins($margin, $margin, $margin);
        $pdf->SetTopMargin($margin);
        $pdf->SetAutoPageBreak(true, $margin);
        $pdf->SetHeaderMargin(0);
        $pdf->SetFooterMargin(0);
        $pdf->AddPage();
        $pdf->SetY($margin);

        $pagewidth = $pdf->getPageWidth() - $pdf->getMargins()['left'] - $pdf->getMargins()['right'];
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->MultiCell($pagewidth, 7, $title, 0, 'C', false, 1);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->MultiCell($pagewidth, 5, $label . ' — ' . $instrument, 0, 'C', false, 1);
        $pdf->Ln(6);

        $esc  = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $hs   = 'background-color:#6c63ff;color:#ffffff;font-weight:bold;font-size:9pt;padding:4px;';
        $td   = 'font-size:9pt;padding:4px;border:1px solid #cccccc;';
        $tda  = 'font-size:9pt;padding:4px;border:1px solid #cccccc;background-color:#f9f9f9;';

        $html = '<table border="1" cellpadding="3" cellspacing="0" style="width:100%;">';

        switch ($correction_type) {
            case 'clave_correccion':
                $html .= "<tr><th style=\"{$hs}width:60%;\">Pregunta / Ítem</th><th style=\"{$hs}\">Respuesta correcta</th></tr>";
                foreach (($data['items'] ?? []) as $i => $item) {
                    $item = (array)$item;
                    $q    = $esc($item['question'] ?? $item['pregunta'] ?? '');
                    $mas  = $item['model_answers'] ?? null;
                    if (is_array($mas) && !empty($mas)) {
                        $ans = 'Respuesta abierta: ' . $esc(implode(' / ', array_map('strval', $mas)));
                    } else {
                        $ans = $esc((string)($item['answer'] ?? $item['respuesta'] ?? ''));
                    }
                    $s = ($i % 2 === 1) ? $tda : $td;
                    $html .= "<tr><td style=\"{$s}\">{$q}</td><td style=\"{$s}\">{$ans}</td></tr>";
                }
                break;

            case 'lista_cotejo':
                $html .= "<tr><th style=\"{$hs}width:70%;\">Criterio</th><th style=\"{$hs}\">Logrado</th><th style=\"{$hs}\">No logrado</th></tr>";
                foreach (($data['criteria'] ?? $data['criterios'] ?? []) as $i => $item) {
                    $item = (array)$item;
                    $c    = $esc($item['criterion'] ?? $item['criterio'] ?? '');
                    $s    = ($i % 2 === 1) ? $tda : $td;
                    $html .= "<tr><td style=\"{$s}\">{$c}</td><td style=\"{$s}\" align=\"center\">&#9744;</td><td style=\"{$s}\" align=\"center\">&#9744;</td></tr>";
                }
                break;

            case 'escala_valoracion':
                $items  = $data['criteria'] ?? $data['criterios'] ?? [];
                $levels = $data['levels'] ?? $data['niveles'] ?? ['Insuficiente', 'Suficiente', 'Bueno', 'Destacado'];
                $html  .= "<tr><th style=\"{$hs}\">Criterio</th>";
                foreach ($levels as $lv) { $html .= "<th style=\"{$hs}\">" . $esc((string)$lv) . '</th>'; }
                $html  .= '</tr>';
                foreach ($items as $i => $item) {
                    $item  = (array)$item;
                    $c     = $esc($item['criterion'] ?? $item['criterio'] ?? '');
                    $s     = ($i % 2 === 1) ? $tda : $td;
                    $html .= "<tr><td style=\"{$s}\">{$c}</td>";
                    foreach ($levels as $lv) { $html .= "<td style=\"{$s}\" align=\"center\">&#9675;</td>"; }
                    $html .= '</tr>';
                }
                break;

            case 'rubrica':
                $criteria = $data['rubric_criteria'] ?? $data['criteria'] ?? [];
                $first    = reset($criteria);
                $levels   = [];
                if (is_array($first) && !empty($first['levels'])) {
                    foreach ($first['levels'] as $lvl) { $levels[] = $lvl['label'] ?? '—'; }
                } else {
                    $levels = $data['levels'] ?? ['Insuficiente', 'Suficiente', 'Bueno', 'Destacado'];
                }
                $html .= "<tr><th style=\"{$hs}width:22%;\">Criterio</th>";
                foreach ($levels as $lv) { $html .= "<th style=\"{$hs}\">" . $esc((string)$lv) . '</th>'; }
                $html .= '</tr>';
                foreach ($criteria as $i => $crit) {
                    $crit  = (array)$crit;
                    $cname = $esc($crit['name'] ?? $crit['criterion'] ?? '');
                    if (isset($crit['weight'])) { $cname .= ' (' . (int)$crit['weight'] . '%)'; }
                    $s     = ($i % 2 === 1) ? $tda : $td;
                    $html .= "<tr><td style=\"{$s}\"><b>{$cname}</b></td>";
                    foreach (($crit['levels'] ?? []) as $lvl) {
                        $lvl  = (array)$lvl;
                        $desc = $esc($lvl['description'] ?? '');
                        if (isset($lvl['score'])) { $desc .= ' <i>(' . $esc((string)$lvl['score']) . ')</i>'; }
                        $html .= "<td style=\"{$s}\">{$desc}</td>";
                    }
                    $missing = count($levels) - count($crit['levels'] ?? []);
                    for ($j = 0; $j < $missing; $j++) { $html .= "<td style=\"{$s}\">—</td>"; }
                    $html .= '</tr>';
                }
                break;
        }
        $html .= '</table>';

        $pdf->writeHTML($html, true, false, true, false, '');

        /* Justificación pedagógica - temporalmente oculta
        if (!empty($data['justification'])) {
            $pdf->Ln(6);
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, 'Justificación pedagógica:', 0, 1);
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->MultiCell(0, 5, $data['justification'], 0, 'L');
        }
        */

        return $pdf->Output('', 'S');
    }
}
