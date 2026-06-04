<?php
namespace local_areteia\steps;

defined('MOODLE_INTERNAL') || die();

use html_writer;
use moodle_url;
use local_areteia\session_manager;
use local_areteia\rag_client;
use local_areteia\step_renderer;

/**
 * Step 5 — Diseño del instrumento.
 * AI generates the evaluation instrument content (questions, items, rubric).
 */
class step5 {

    public static function render(array $ctx): void {
        global $PAGE, $OUTPUT;

        $id         = $ctx['id'];
        $context    = $ctx['context'];
        $do_gen     = optional_param('do_gen', 0, PARAM_INT);
        $num_items  = optional_param('num_items', 5, PARAM_INT);
        $instrument = session_manager::get('instrument', '');
        if (empty($instrument)) {
            $instrument = optional_param('instrument', '', PARAM_TEXT);
        }
        $d2         = session_manager::get('d2', '');
        $inst_content = session_manager::get('inst_content', '');
        
        // Auto-trigger generation if content is empty and no explicit command is given
        if (empty($inst_content) && !$do_gen) {
            $do_gen = 1;
        }
        $usage = session_manager::get('s5_usage', null);

        $link_params = ['id' => $id];

        // Generate content if requested
        if ($do_gen) {
            $feedback = optional_param('feedback', '', PARAM_TEXT);
            $res_data = rag_client::generate([
                'course_id'         => $id,
                'course_title'      => $PAGE->course->fullname,
                'step'              => 5,
                'objective'         => $d2,
                'objective_json'    => session_manager::get('d2_json', ''),
                'd1_content'        => session_manager::get('d1', ''),
                'd3_function'       => session_manager::get('d3', ''),
                'd4_modality'       => session_manager::get('d4', ''),
                'chosen_instrument' => $instrument,
                'num_items'         => $num_items,
                'feedback'          => $feedback
            ]);
            if ($res_data && $res_data->status == 'success') {
                $inst_content = json_encode($res_data->output);
                session_manager::set('inst_content', $inst_content);
                if (!empty($res_data->usage)) {
                    session_manager::set('s5_usage', (array)$res_data->usage);
                }
                
                // PRG Pattern: Redirect to clean URL to prevent redundant generation on reload
                $clean_url = new moodle_url($PAGE->url, ['id' => $id, 'step' => 5]);
                redirect($clean_url);
            } else {
                $err = $res_data->message ?? 'Error al generar el diseño.';
                if (!empty($res_data->reason)) $err .= " Motivo: " . $res_data->reason;
                echo $OUTPUT->notification('Error de IA: ' . $err, 'error');
                $inst_content = '';
            }
        }

        echo html_writer::tag('p', 'Revisa y selecciona los mejores ítems para tu evaluación', ['class' => 'areteia-stitle']);
        
        step_renderer::render_rag_info();

        $usage = session_manager::get('s5_usage', null);
        if (!empty($usage)) {
            $usage_json = json_encode($usage);
            echo "<script>console.log('AI Token Usage (Step 5):', {$usage_json});</script>";
        }

        // Instrument summary card
        echo html_writer::start_tag('div', [
            'class' => 'areteia-card',
            'style' => 'background:#f9f9f9; padding:15px; border:1px dashed #ccc; margin-bottom:15px;',
        ]);
        echo html_writer::tag('strong', "Instrumento: $instrument", [
            'style' => 'display:block; color:#185fa5;',
        ]);
        echo html_writer::tag('small', "Objetivo: $d2", ['style' => 'color:#666;']);
        echo html_writer::end_tag('div');

        if (empty($inst_content)) {
            echo html_writer::start_tag('div', [
                'style' => 'text-align:center; padding:40px; border:2px dashed #eee; border-radius:15px;',
            ]);
            echo html_writer::tag('p',
                'La IA generará una lista de ítems propuestos. Podrás elegir con cuáles quedarte.',
                ['style' => 'color:#777; margin-bottom:20px;']
            );
            $gen_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 5, 'do_gen' => 1]));
            echo html_writer::start_tag('div', ['style' => 'display:flex; justify-content:center; align-items:center; gap:10px;']);
            echo \local_areteia\step_renderer::render_preview_button(5);
            echo html_writer::link($gen_url, '✨ Generar Propuestas con IA', [
                'class' => 'areteia-btn areteia-btn-primary',
                'style' => 'padding:12px 25px;',
            ]);
            echo html_writer::end_tag('div');
            echo html_writer::end_tag('div');
        } else {
            // Global feedback removed in favor of per-item adjustment

            // Parse and render structured content
            $data = json_decode($inst_content, true);
            if (!$data || !is_array($data)) {
                echo html_writer::tag('div', 'Error decodificando la respuesta de la IA.', ['class' => 'alert alert-danger']);
            } else {
                // Ensure items is a list for JS
                $data['items'] = array_values($data['items'] ?? []);
                
                echo html_writer::tag('h3', $data['title'] ?? 'Propuesta de Ítems', ['style' => 'color:#185fa5; margin-bottom:15px;']);

                // --- SCENARIO / NARRATIVE BOX ---
                if (!empty($data['scenario'])) {
                    echo html_writer::start_tag('div', [
                        'class' => 'areteia-card',
                        'style' => 'background:#fffbea; border-left:5px solid #f59e0b; padding:20px; margin-bottom:20px; border-radius:8px;'
                    ]);
                    echo html_writer::tag('div',
                        '📖 <strong>Escenario / Contexto del Instrumento</strong>',
                        ['style' => 'color:#92400e; font-size:13px; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:10px; display:block;']
                    );
                    echo html_writer::tag('div',
                        format_text($data['scenario'], FORMAT_MARKDOWN),
                        ['style' => 'font-size:14px; line-height:1.7; color:#1a1a1a;']
                    );
                    echo html_writer::end_tag('div');
                }
                
                // Form for item selection
                echo html_writer::start_tag('form', [
                    'id' => 'item-selection-form',
                    'method' => 'POST',
                    'action' => (new moodle_url($PAGE->url, array_merge($link_params, ['step' => 7])))->out(false)
                ]);
                echo html_writer::start_tag('div', ['style' => 'display:flex; flex-direction:column; gap:15px; margin-bottom:20px;']);
                
                foreach (($data['items'] ?? []) as $index => $item) {
                    $item_id = "item_" . $index;
                    $type = strtolower($item['type'] ?? '');
                    
                    echo html_writer::start_tag('div', [
                        'class' => 'areteia-card item-card',
                        'style' => 'padding:20px; border-left:4px solid #6c63ff; margin-bottom:15px; position:relative;'
                    ]);
                    
                    echo html_writer::start_tag('div', ['style' => 'display:flex; gap:15px; align-items:flex-start;']);
                    
                    // Selection Checkbox
                    echo html_writer::checkbox('selected_items[]', $index, true, '', [
                        'id' => $item_id,
                        'class' => 'item-cb',
                        'style' => 'transform:scale(1.4); margin-top:5px;'
                    ]);
                    
                    echo html_writer::start_tag('div', ['style' => 'flex-grow:1;']);
                    
                    // Header: Type + Status Badges
                    echo html_writer::start_tag('div', ['style' => 'display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;']);
                    echo html_writer::tag('span', $item['type'] ?? 'Ítem ' . ($index + 1), [
                        'style' => 'font-weight:700; color:#185fa5; text-transform:uppercase; font-size:11px; letter-spacing:0.05em;'
                    ]);
                    echo html_writer::start_tag('div', ['style' => 'display:flex; gap:6px;']);
                    echo html_writer::tag('span', $item['difficulty'] ?? 'N/A', ['class' => 'areteia-tag', 'style' => 'background:#fff3e0; color:#e65100; font-size:10px;']);
                    if (!empty($item['points'])) {
                        echo html_writer::tag('span', $item['points'] . ' pts', ['class' => 'areteia-tag', 'style' => 'background:#e6f4ea; color:#1e8e3e; font-size:10px;']);
                    }
                    echo html_writer::end_tag('div');
                    echo html_writer::end_tag('div');
                    
                    // Consiga (Question Body)
                    echo html_writer::start_tag('div', ['class' => 'item-body-text', 'style' => 'font-size:14px; margin-bottom:15px; color:#1a1a1a; line-height:1.6;']);
                    echo format_text($item['consiga'] ?? 'Sin texto', FORMAT_MARKDOWN);
                    echo html_writer::end_tag('div');
                    
                    // --- RICH VISUALIZATION BY TYPE ---
                    echo html_writer::start_tag('div', ['class' => 'item-rich-content']);
                    
                    if (strpos($type, 'múltiple') !== false || strpos($type, 'selección') !== false || strpos($type, 'cerrada') !== false) {
                        // Options list — highlight the correct option
                        if (!empty($item['alternativas'])) {
                            foreach ($item['alternativas'] as $i => $opt) {
                                $is_correct = isset($item['correct_index']) && (int)$item['correct_index'] === $i;
                                $opt_style = $is_correct ? 'background:#e8f5e9; border-left:3px solid #2e7d32; padding-left:8px;' : '';
                                echo html_writer::start_tag('div', ['class' => 'item-option', 'style' => $opt_style]);
                                $marker = $is_correct ? '✓ ' : '○ ';
                                $marker_style = 'font-style:normal; ' . ($is_correct ? 'color:#2e7d32; font-weight:bold;' : 'opacity:0.5;');
                                echo html_writer::tag('span', $marker, ['style' => $marker_style]);
                                echo s($opt);
                                echo html_writer::end_tag('div');
                            }
                        }
                    } else if (strpos($type, 'verdadero') !== false) {
                        // True/False badges — highlight the correct one
                        $correct_bool = $item['correct_boolean'] ?? null;
                        echo html_writer::start_tag('div', ['style' => 'display:flex; gap:10px;']);
                        $v_style = $correct_bool === true  ? 'border:2px solid #2e7d32; font-weight:bold;' : '';
                        $f_style = $correct_bool === false ? 'border:2px solid #2e7d32; font-weight:bold;' : '';
                        echo html_writer::tag('span', ($correct_bool === true  ? '✓ ' : '') . 'Verdadero', ['class' => 'item-vf-badge item-vf-v', 'style' => $v_style]);
                        echo html_writer::tag('span', ($correct_bool === false ? '✓ ' : '') . 'Falso',     ['class' => 'item-vf-badge item-vf-f', 'style' => $f_style]);
                        echo html_writer::end_tag('div');
                    } else if (strpos($type, 'abierta') !== false || strpos($type, 'ensayo') !== false) {
                        // Open box placeholder
                        echo html_writer::tag('div', 'El estudiante redactará su respuesta aquí...', [
                            'style' => 'border:1px dashed #ccc; padding:15px; border-radius:8px; color:#999; font-style:italic; font-size:12px;'
                        ]);
                        // Show expected short answer if available
                        if (!empty($item['short_answer'])) {
                            echo html_writer::tag('div', '✓ Respuesta esperada: ' . s($item['short_answer']), [
                                'style' => 'font-size:11px; color:#2e7d32; margin-top:6px;'
                            ]);
                        }
                    } else {
                        // Default Fallback
                        if (!empty($item['alternativas'])) {
                            foreach ($item['alternativas'] as $i => $opt) {
                                $is_correct = isset($item['correct_index']) && (int)$item['correct_index'] === $i;
                                $prefix = $is_correct ? '<span style="color:#2e7d32; font-weight:bold;">✓ </span>' : '• ';
                                echo html_writer::tag('div', $prefix . s($opt), ['style' => 'font-size:13px; color:#555; margin-bottom:4px;']);
                            }
                        }
                    }
                    echo html_writer::end_tag('div');
                    
                    // Objectives and Meta
                    if (!empty($item['objectives'])) {
                        echo html_writer::tag('div', '🎯 <strong>Objetivos:</strong> ' . implode(', ', array_map('s', $item['objectives'])), [
                            'style' => 'font-size:11px; color:#777; margin-top:10px;'
                        ]);
                    }

                    // --- PER-ITEM ADJUSTMENT UI ---
                    echo html_writer::start_tag('div', ['class' => 'item-action-bar', 'style' => 'margin-top:15px; border-top:1px solid #f0f0f0; padding-top:10px; display:flex; gap:8px; flex-wrap:wrap;']);
                    echo html_writer::tag('button', 'Ajustar con IA ✨', [
                        'type' => 'button',
                        'class' => 'item-adjust-trigger',
                        'data-index' => $index
                    ]);
                    echo html_writer::tag('button', '✏️ Editar', [
                        'type' => 'button',
                        'class' => 'item-edit-trigger',
                        'data-index' => $index
                    ]);
                    echo html_writer::end_tag('div');

                    // AI adjustment tray
                    echo html_writer::start_tag('div', [
                        'class' => 'item-adjust-tray',
                        'data-index' => $index
                    ]);
                    echo html_writer::tag('textarea', '', [
                        'class' => 'item-adjust-textarea',
                        'placeholder' => 'Ej: Hazla más difícil, cambia el enfoque a la praxis...'
                    ]);
                    $adj_url = new moodle_url($PAGE->url, ['action' => 'adjust_item', 'id' => $id, 'sesskey' => sesskey()]);
                    echo html_writer::link($adj_url, 'Actualizar ítem', [
                        'class' => 'areteia-btn areteia-btn-primary',
                        'style' => 'font-size:11px; padding:4px 12px;',
                        'data-adjust' => '1',
                        'data-item-index' => $index
                    ]);
                    echo html_writer::end_tag('div');

                    // Manual edit tray
                    $current_consiga   = $item['consiga']    ?? '';
                    $current_diff      = $item['difficulty'] ?? 'Media';
                    $current_points    = $item['points']     ?? 1;
                    $save_url = new moodle_url($PAGE->url, ['action' => 'save_item', 'id' => $id]);
                    echo html_writer::start_tag('div', [
                        'class' => 'item-edit-tray',
                        'data-index' => $index
                    ]);
                    echo html_writer::start_tag('form', [
                        'method' => 'post',
                        'action' => $save_url->out(false),
                        'style'  => 'margin:0;'
                    ]);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'item_index', 'value' => $index]);
                    echo html_writer::tag('label', 'Consigna / Pregunta', [
                        'style' => 'font-size:11px; font-weight:600; display:block; margin-bottom:4px; color:#555;'
                    ]);
                    echo html_writer::tag('textarea', s($current_consiga), [
                        'name'  => 'item_consiga',
                        'rows'  => 3,
                        'class' => 'item-adjust-textarea',
                        'style' => 'margin-bottom:8px;'
                    ]);
                    echo html_writer::start_tag('div', ['style' => 'display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;']);
                    // Difficulty select
                    echo html_writer::start_tag('div');
                    echo html_writer::tag('label', 'Dificultad', ['style' => 'font-size:11px; font-weight:600; display:block; margin-bottom:3px; color:#555;']);
                    echo html_writer::start_tag('select', ['name' => 'item_difficulty', 'style' => 'font-size:12px; border-radius:4px; border:1px solid #ddd; padding:3px 6px;']);
                    foreach (['Fácil', 'Media', 'Difícil'] as $diff_opt) {
                        $attrs = ['value' => $diff_opt];
                        if ($diff_opt === $current_diff) $attrs['selected'] = 'selected';
                        echo html_writer::tag('option', s($diff_opt), $attrs);
                    }
                    echo html_writer::end_tag('select');
                    echo html_writer::end_tag('div');
                    // Points input
                    echo html_writer::start_tag('div');
                    echo html_writer::tag('label', 'Puntos', ['style' => 'font-size:11px; font-weight:600; display:block; margin-bottom:3px; color:#555;']);
                    echo html_writer::empty_tag('input', [
                        'type'  => 'number',
                        'name'  => 'item_points',
                        'value' => $current_points,
                        'min'   => '0.5',
                        'step'  => '0.5',
                        'style' => 'font-size:12px; border-radius:4px; border:1px solid #ddd; padding:3px 6px; width:70px;'
                    ]);
                    echo html_writer::end_tag('div');
                    // Action buttons
                    echo html_writer::start_tag('div', ['style' => 'display:flex; gap:6px;']);
                    echo html_writer::tag('button', 'Guardar', [
                        'type'  => 'submit',
                        'style' => 'font-size:11px; padding:4px 12px; background:#28a745; color:#fff; border:none; border-radius:4px; cursor:pointer;'
                    ]);
                    echo html_writer::tag('button', 'Cancelar', [
                        'type'        => 'button',
                        'class'       => 'item-edit-cancel',
                        'data-index'  => $index,
                        'style'       => 'font-size:11px; padding:4px 10px; background:none; border:1px solid #bbb; border-radius:4px; cursor:pointer; color:#666;'
                    ]);
                    echo html_writer::end_tag('div');
                    echo html_writer::end_tag('div');
                    echo html_writer::end_tag('form');
                    echo html_writer::end_tag('div'); // item-edit-tray
                    
                    echo html_writer::end_tag('div'); // body div
                    echo html_writer::end_tag('div'); // flex container div
                    echo html_writer::end_tag('div'); // card container div
                } // End items loop
                echo html_writer::end_tag('div'); // areteia-inner div wrapper (items container)

                // Justification
                if (!empty($data['justification'])) {
                    echo html_writer::start_tag('div', ['style' => 'font-size:12px; color:#666; font-style:italic; padding:15px; background:#f9f9f9; border-radius:10px; margin-bottom:20px; border:1px solid #eee;']);
                    echo html_writer::tag('strong', '💡 Justificación Pedagógica: ', ['style' => 'color:#185fa5;']);
                    echo s($data['justification']);
                    echo html_writer::end_tag('div');
                }
                
                // Securely store the source data for PHP processing in Step 7
                echo html_writer::empty_tag('input', [
                    'type' => 'hidden',
                    'name' => 'src_data_payload',
                    'value' => s(json_encode($data))
                ]);
                
                echo html_writer::end_tag('form');
            }

            // Inner nav
            echo html_writer::start_tag('div', [
                'class' => 'areteia-nav',
                'style' => 'margin-top:20px; border-top:1px solid #eee; padding-top:15px;',
            ]);
            echo html_writer::link(
                new moodle_url($PAGE->url, array_merge($link_params, ['step' => 4])),
                '← Volver',
                ['class' => 'areteia-btn']
            );

            echo html_writer::tag('button', 'Configurar Evaluación Final →', [
                'id'    => 'btn-go-to-step6',
                'type'  => 'submit',
                'form'  => 'item-selection-form',
                'class' => 'areteia-btn areteia-btn-primary',
                'style' => 'background:#185fa5; border-color:#185fa5;'
            ]);
            
            echo html_writer::end_tag('div');
        }
    }
}
