<?php
namespace local_areteia\steps;

defined('MOODLE_INTERNAL') || die();

use html_writer;
use moodle_url;
use local_areteia\session_manager;
use local_areteia\step_renderer;
use local_areteia\encaje_table;

/**
 * crit_finalize — Vista final del instrumento de corrección (Action: crit, Step: 1).
 */
class crit_finalize {

    public static function render(array $ctx): void {
        global $PAGE;

        $id = $ctx['id'];
        $instrument = session_manager::get('instrument', '');
        $correction = session_manager::get('correction_instrument', '');
        $correction_content = session_manager::get('correction_content', '');
        $link_params = ['id' => $id, 'action' => 'crit'];

        $corr_label = encaje_table::LABELS[$correction] ?? $correction;
        $corr_icon = encaje_table::ICONS[$correction] ?? '📄';

        echo html_writer::tag('p', 'Instrumento de corrección finalizado', ['class' => 'areteia-stitle']);

        // Guard
        if (empty($correction_content)) {
            echo html_writer::tag('div', 'No hay instrumento de corrección generado. Vuelve al paso anterior.', ['class' => 'alert alert-warning']);
            $prev_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 0]));
            step_renderer::render_nav(1, $prev_url);
            return;
        }

        // Summary header
        echo html_writer::start_tag('div', [
            'class' => 'areteia-card',
            'style' => 'background:#f0f4ff; border:1px solid #d0d8f0; padding:15px; margin-bottom:20px;'
        ]);
        echo html_writer::tag('div', "📝 Evaluación: <strong>$instrument</strong>", ['style' => 'font-size:13px; color:#555; margin-bottom:5px;']);
        echo html_writer::tag('div', "$corr_icon Corrección: <strong>$corr_label</strong>", ['style' => 'font-size:13px; color:#2e7d32;']);
        echo html_writer::end_tag('div');

        // Render the correction instrument
        echo html_writer::start_tag('div', [
            'class' => 'areteia-card',
            'style' => 'padding:20px; margin-bottom:20px;'
        ]);

        $data = json_decode($correction_content, true);
        if (is_array($data)) {
            // Title
            if (!empty($data['title'])) {
                echo html_writer::tag('h3', s($data['title']), ['style' => 'color:#185fa5; margin-bottom:15px;']);
            }

            // Render correction instrument
            self::render_correction_instrument($correction, $data);

            // Justification
            if (!empty($data['justification'])) {
                echo html_writer::start_tag('div', ['style' => 'font-size:12px; color:#666; font-style:italic; padding:15px; background:#f9f9f9; border-radius:10px; margin-top:20px; border:1px solid #eee;']);
                echo html_writer::tag('strong', '💡 Justificación Pedagógica: ', ['style' => 'color:#185fa5;']);
                echo s($data['justification'] ?? '');
                echo html_writer::end_tag('div');
            }
        } else {
            echo html_writer::tag('div', 'Error decodificando el instrumento.', ['class' => 'alert alert-danger']);
        }

        echo html_writer::end_tag('div');

        // Completion banner
        echo html_writer::start_tag('div', [
            'class' => 'areteia-card',
            'style' => 'border-left:5px solid #28a745; background:#f4fff4; padding:15px;'
        ]);
        echo html_writer::tag('strong', '✅ Instrumento de corrección completado', ['style' => 'color:#28a745; display:block; margin-bottom:5px;']);
        echo html_writer::tag('p', 'Tu instrumento de corrección está listo. Puedes volver al paso anterior para refinarlo o usar la evaluación en tu curso.', [
            'style' => 'font-size:12px; margin:0; color:#555;'
        ]);
        echo html_writer::end_tag('div');

        // Navigation
        $prev_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 0]));
        step_renderer::render_nav(1, $prev_url, null, '', [], '✔ Completado');
    }

    /**
     * Public wrapper for rendering correction instruments (used by crit_selection and crit_finalize).
     */
    public static function render_correction_public(string $type, array $data): void {
        self::render_correction_instrument($type, $data);
    }

    private static function render_correction_instrument(string $type, array $data): void {
        switch ($type) {
            case 'clave_correccion':
                self::render_answer_key($data);
                break;
            case 'lista_cotejo':
                self::render_checklist($data);
                break;
            case 'escala_valoracion':
                self::render_rating_scale($data);
                break;
            case 'rubrica':
                self::render_rubric($data);
                break;
            default:
                // Generic fallback
                echo html_writer::tag('pre', s(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)), [
                    'style' => 'background:#f9f9f9; padding:15px; border-radius:8px; font-size:12px; overflow-x:auto;'
                ]);
        }
    }

    /** 🔑 Clave de corrección: question → correct answer */
    private static function render_answer_key(array $data): void {
        $items = $data['items'] ?? $data['answers'] ?? $data;
        if (!is_array($items) || empty($items)) {
            echo html_writer::tag('p', 'Sin datos para mostrar.', ['style' => 'color:#999;']);
            return;
        }

        echo html_writer::start_tag('table', ['class' => 'generaltable', 'style' => 'width:100%; font-size:13px;']);
        echo '<thead><tr><th style="width:60%;">Pregunta / Ítem</th><th>Respuesta correcta</th></tr></thead><tbody>';
        foreach ($items as $item) {
            $item = (array)$item;
            $q_val = $item['question'] ?? $item['pregunta'] ?? $item['text'] ?? '';
            $a_val = $item['answer'] ?? $item['respuesta'] ?? $item['correct'] ?? '';
            
            // Flatten arrays/objects if AI hallucinated format
            if (is_array($q_val) || is_object($q_val)) {
                $q_val = is_array($q_val) && count($q_val) > 0 && is_string($q_val[0]) 
                    ? implode(' ', $q_val) 
                    : json_encode($q_val, JSON_UNESCAPED_UNICODE);
            }
            $q = s((string)$q_val);

            // Format answer nicely if it's an array (like matching questions)
            if (is_array($a_val) || is_object($a_val)) {
                $a_val = (array)$a_val;
                $formatted_ans = [];
                foreach ($a_val as $sub_item) {
                    if (is_array($sub_item) || is_object($sub_item)) {
                        $sub_item = (array)$sub_item;
                        $premise = $sub_item['premise'] ?? $sub_item['premisa'] ?? $sub_item['key'] ?? '';
                        $ans = $sub_item['answer'] ?? $sub_item['respuesta'] ?? $sub_item['value'] ?? '';
                        if ($premise && $ans) {
                            $formatted_ans[] = "<strong>" . s($premise) . ":</strong> " . s($ans);
                        } else {
                            $formatted_ans[] = s(json_encode($sub_item, JSON_UNESCAPED_UNICODE));
                        }
                    } else {
                        $formatted_ans[] = s((string)$sub_item);
                    }
                }
                $a = implode('<br><span style="color:#666; font-size:11px;">---</span><br>', $formatted_ans);
            } else {
                if (is_bool($a_val)) $a_val = $a_val ? 'Verdadero' : 'Falso';
                $a = s((string)$a_val);
            }
            
            echo "<tr><td>{$q}</td><td style=\"color:#2e7d32; font-weight:600;\">{$a}</td></tr>";
        }
        echo '</tbody></table>';
    }

    /** ✅ Lista de cotejo: criterion → Sí/No */
    private static function render_checklist(array $data): void {
        $items = $data['criteria'] ?? $data['criterios'] ?? $data['items'] ?? $data;
        if (!is_array($items) || empty($items)) {
            echo html_writer::tag('p', 'Sin datos para mostrar.', ['style' => 'color:#999;']);
            return;
        }

        echo html_writer::start_tag('table', ['class' => 'generaltable', 'style' => 'width:100%; font-size:13px;']);
        echo '<thead><tr><th style="width:70%;">Criterio</th><th style="text-align:center;">Logrado</th><th style="text-align:center;">No logrado</th></tr></thead><tbody>';
        foreach ($items as $item) {
            $item = (array)$item;
            $c_val = $item['criterion'] ?? $item['criterio'] ?? $item['text'] ?? '';
            if (is_array($c_val) || is_object($c_val)) $c_val = json_encode($c_val, JSON_UNESCAPED_UNICODE);
            $c = s((string)$c_val);

            echo "<tr><td>{$c}</td><td style=\"text-align:center;\">☐</td><td style=\"text-align:center;\">☐</td></tr>";
        }
        echo '</tbody></table>';
    }

    /** 📊 Escala de valoración: criterion × levels */
    private static function render_rating_scale(array $data): void {
        $items = $data['criteria'] ?? $data['criterios'] ?? $data['items'] ?? $data;
        $levels = $data['levels'] ?? $data['niveles'] ?? ['Insuficiente', 'Suficiente', 'Bueno', 'Destacado'];

        if (!is_array($items) || empty($items)) {
            echo html_writer::tag('p', 'Sin datos para mostrar.', ['style' => 'color:#999;']);
            return;
        }

        $level_count = count($levels);
        echo html_writer::start_tag('table', ['class' => 'generaltable', 'style' => 'width:100%; font-size:13px;']);
        echo '<thead><tr><th style="width:40%;">Criterio</th>';
        foreach ($levels as $lv) {
            $lv = is_array($lv) ? ($lv['label'] ?? $lv['name'] ?? '') : $lv;
            if (is_array($lv) || is_object($lv)) $lv = json_encode($lv, JSON_UNESCAPED_UNICODE);
            echo '<th style="text-align:center; font-size:11px;">' . s((string)$lv) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($items as $item) {
            $item = (array)$item;
            $c_val = $item['criterion'] ?? $item['criterio'] ?? $item['text'] ?? '';
            if (is_array($c_val) || is_object($c_val)) $c_val = json_encode($c_val, JSON_UNESCAPED_UNICODE);
            $c = s((string)$c_val);

            echo "<tr><td>{$c}</td>";
            for ($i = 0; $i < $level_count; $i++) {
                echo '<td style="text-align:center;">○</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /** 📋 Rúbrica: criterion × levels with descriptors */
    private static function render_rubric(array $data): void {
        $items = $data['criteria'] ?? $data['criterios'] ?? $data['items'] ?? $data;
        $levels = $data['levels'] ?? $data['niveles'] ?? [];

        if (!is_array($items) || empty($items)) {
            echo html_writer::tag('p', 'Sin datos para mostrar.', ['style' => 'color:#999;']);
            return;
        }

        // Determine level headers from data
        $level_headers = [];
        if (!empty($levels)) {
            foreach ($levels as $lv) {
                $level_headers[] = is_array($lv) ? ($lv['label'] ?? $lv['name'] ?? '') : $lv;
            }
        } else {
            // Infer from first item's descriptors
            $first = (array)reset($items);
            $descs = $first['descriptors'] ?? $first['descriptores'] ?? $first['levels'] ?? [];
            foreach ($descs as $d) {
                $d = (array)$d;
                $level_headers[] = $d['level'] ?? $d['nivel'] ?? '';
            }
        }

        $level_count = max(count($level_headers), 1);

        // Color gradient for level columns (green→red)
        $colors = ['#ffebee', '#fff3e0', '#e8f5e9', '#c8e6c9'];
        if ($level_count > 4) {
            $colors = array_pad($colors, $level_count, '#e8f5e9');
        }

        echo html_writer::start_tag('div', ['style' => 'overflow-x:auto;']);
        echo html_writer::start_tag('table', ['class' => 'generaltable', 'style' => 'width:100%; font-size:12px; border-collapse:collapse;']);

        // Header
        echo '<thead><tr><th style="width:20%; vertical-align:bottom; padding:10px;">Criterio</th>';
        foreach ($level_headers as $idx => $lh) {
            $bg = $colors[$idx] ?? '#f5f5f5';
            if (is_array($lh) || is_object($lh)) $lh = json_encode($lh, JSON_UNESCAPED_UNICODE);
            echo '<th style="text-align:center; padding:10px; background:' . $bg . '; font-size:11px;">' . s((string)$lh) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($items as $item) {
            $item = (array)$item;
            $c_val = $item['criterion'] ?? $item['criterio'] ?? $item['text'] ?? '';
            if (is_array($c_val) || is_object($c_val)) $c_val = json_encode($c_val, JSON_UNESCAPED_UNICODE);
            $c = s((string)$c_val);
            
            $weight = isset($item['weight']) ? ' (' . $item['weight'] . '%)' : '';

            echo '<tr><td style="font-weight:600; padding:10px; vertical-align:top; border-right:2px solid #ddd;">' . $c . $weight . '</td>';

            $descs = $item['descriptors'] ?? $item['descriptores'] ?? $item['levels'] ?? [];
            foreach ($descs as $idx => $d) {
                $d = (array)$d;
                $t_val = $d['description'] ?? $d['descriptor'] ?? $d['text'] ?? '';
                if (is_array($t_val) || is_object($t_val)) $t_val = json_encode($t_val, JSON_UNESCAPED_UNICODE);
                $text = s((string)$t_val);
                
                $bg = $colors[$idx] ?? '#f5f5f5';
                echo '<td style="padding:10px; font-size:11px; line-height:1.5; background:' . $bg . '; vertical-align:top;">' . $text . '</td>';
            }

            // Fill empty cells if descriptor count < level count
            $missing = $level_count - count($descs);
            for ($i = 0; $i < $missing; $i++) {
                echo '<td style="padding:10px;">—</td>';
            }

            echo '</tr>';
        }

        echo '</tbody></table>';
        echo html_writer::end_tag('div');
    }
}
