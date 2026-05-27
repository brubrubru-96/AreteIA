<?php
namespace local_areteia\steps;

defined('MOODLE_INTERNAL') || die();

use html_writer;
use moodle_url;
use local_areteia\session_manager;
use local_areteia\rag_client;
use local_areteia\step_renderer;

/**
 * eval_design — Diseño del instrumento (Action: eval, Step: 2).
 * AI generates the evaluation instrument content (questions, items, rubric).
 */
class eval_design {

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
        // Load objectives with Bloom levels for lookup during rendering
        $d2_json = session_manager::get('d2_json', '');
        $objectives_data = json_decode($d2_json, true) ?: [];
        $objective_map = [];
        
        // Build map by index position (Obj 1, Obj 2, etc.) for LLM references
        foreach ($objectives_data as $idx => $obj) {
            if (!empty($obj['text'])) {
                $ref_key = 'Obj ' . ($idx + 1);
                $objective_map[$ref_key] = [
                    'bloom' => $obj['bloom'] ?? '',
                    'text' => $obj['text']
                ];
            }
        }
        // Register objectives map globally for JS updates
        echo html_writer::tag('script', 'window.areteiaObjectivesMap = ' . json_encode($objective_map) . ';', ['type' => 'text/javascript']);
        // Debug: Log what's in the objective_map
        echo "<script>console.log('DEBUG - Objective Map:', " . json_encode($objective_map) . ");</script>";
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
                $clean_url = new moodle_url($PAGE->url, ['id' => $id, 'step' => 2]);
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
            echo "<script>console.log('AI Token Usage (Step 2 - Design):', {$usage_json});</script>";
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
            $gen_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 2, 'do_gen' => 1]));
            echo html_writer::start_tag('div', ['style' => 'display:flex; justify-content:center; align-items:center; gap:10px;']);
            echo \local_areteia\step_renderer::render_preview_button(5);
            echo html_writer::link($gen_url, '✨ Generar Propuestas con IA', [
                'class' => 'areteia-btn areteia-btn-primary',
                'style' => 'padding:12px 25px;',
            ]);
            echo html_writer::end_tag('div');
            echo html_writer::end_tag('div');
        } else {
            // Parse and render structured content
            $data = json_decode($inst_content, true);
            if (!$data || !is_array($data)) {
                echo html_writer::tag('div', 'Error decodificando la respuesta de la IA.', ['class' => 'alert alert-danger']);
            } else {
                // Ensure items is a list for JS
                $data['items'] = array_values($data['items'] ?? []);
                
                echo html_writer::tag('h3', $data['title'] ?? 'Propuesta de Ítems', ['style' => 'color:#185fa5; margin-bottom:15px;']);
                
                // Form for item selection
                echo html_writer::start_tag('form', [
                    'id' => 'item-selection-form',
                    'method' => 'POST',
                    'action' => (new moodle_url($PAGE->url, array_merge($link_params, ['step' => 3])))->out(false)
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
                    
                    // View Mode Container
                    echo html_writer::start_tag('div', ['class' => 'item-view-mode']);
                    
                    // Header: Type + Status Badges
                    echo html_writer::start_tag('div', ['style' => 'display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;']);
                    echo html_writer::tag('span', $item['type'] ?? 'Ítem ' . ($index + 1), [
                        'id' => 'badge-type-' . $index,
                        'style' => 'font-weight:700; color:#185fa5; text-transform:uppercase; font-size:11px; letter-spacing:0.05em;'
                    ]);
                    echo html_writer::start_tag('div', ['style' => 'display:flex; gap:6px;']);
                    echo html_writer::tag('span', $item['difficulty'] ?? 'N/A', ['class' => 'areteia-tag', 'style' => 'background:#fff3e0; color:#e65100; font-size:10px;', 'id' => 'badge-diff-' . $index]);
                    if (!empty($item['points'])) {
                        echo html_writer::tag('span', $item['points'] . ' pts', ['class' => 'areteia-tag', 'style' => 'background:#e6f4ea; color:#1e8e3e; font-size:10px;', 'id' => 'badge-pts-' . $index]);
                    }
                    echo html_writer::end_tag('div');
                    echo html_writer::end_tag('div');
                    
                    // Consigna / Question Body
                    echo html_writer::start_tag('div', ['class' => 'item-body-text', 'id' => 'body-text-' . $index, 'style' => 'font-size:14px; margin-bottom:15px; color:#1a1a1a; line-height:1.6;']);
                    $question_source = $item['consigna'] ?? $item['consiga'] ?? $item['text'] ?? '';
                    if (self::is_lacunar_type($type)) {
                        echo self::render_lacunar_preview($question_source, $item['short_answer'] ?? $item['cloze_answer'] ?? '');
                    } else {
                        echo format_text($question_source ?: 'Sin texto', FORMAT_MARKDOWN);
                    }
                    echo html_writer::end_tag('div');
                    
                    // --- RICH VISUALIZATION BY TYPE ---
                    $lacunar_answer = $item['short_answer'] ?? $item['cloze_answer'] ?? '';
                    $question_source = $item['consigna'] ?? $item['consiga'] ?? $item['text'] ?? '';
                    $show_rich_content = !self::is_lacunar_type($type) || self::should_show_lacunar_answer($lacunar_answer, $question_source);
                    if ($show_rich_content) {
                        echo html_writer::start_tag('div', ['class' => 'item-rich-content', 'id' => 'rich-content-' . $index]);
                    }
                    
                    if (strpos($type, 'múltiple') !== false || strpos($type, 'selección') !== false || strpos($type, 'cerrada') !== false) {
                        // Options list
                        if (!empty($item['alternativas'])) {
                            $correct_index = isset($item['correct_index']) ? (int)$item['correct_index'] : -1;
                            foreach ($item['alternativas'] as $opt_idx => $opt) {
                                $is_correct = ($opt_idx === $correct_index);
                                $option_style = $is_correct ? 'background:#e6f4ea; border-color:#1e8e3e;' : '';
                                echo html_writer::start_tag('div', ['class' => 'item-option', 'style' => $option_style]);
                                if ($is_correct) {
                                    echo html_writer::tag('i', '✔', ['style' => 'font-style:normal; color:#1e8e3e; font-weight:bold;']);
                                } else {
                                    echo html_writer::tag('i', '○', ['style' => 'font-style:normal; opacity:0.5;']);
                                }
                                echo s($opt);
                                echo html_writer::end_tag('div');
                            }
                        }
                    } else if (strpos($type, 'verdadero') !== false) {
                        // True/False badges
                        echo html_writer::start_tag('div', ['style' => 'display:flex; gap:10px;']);
                        echo html_writer::tag('span', 'Verdadero', ['class' => 'item-vf-badge item-vf-v']);
                        echo html_writer::tag('span', 'Falso', ['class' => 'item-vf-badge item-vf-f']);
                        echo html_writer::end_tag('div');
                    } else if (strpos($type, 'match') !== false || strpos($type, 'emparejamiento') !== false || strpos($type, 'orden') !== false) {
                        $pairs = $item['pairs'] ?? [];
                        if (!empty($pairs)) {
                            $table = '<table class="areteia-match-preview-table" style="width:100%; border-collapse:collapse; margin-top:10px;">';
                            $table .= '<thead><tr><th style="padding:8px; border:1px solid #ddd; background:#f5f7fb; text-align:left;">Premisa</th><th style="padding:8px; border:1px solid #ddd; background:#f5f7fb; text-align:left;">Respuesta</th></tr></thead>';
                            $table .= '<tbody>';
                            foreach ($pairs as $pair_idx => $pair) {
                                $left = s($pair['premise'] ?? $pair['premisa'] ?? '');
                                $right = s($pair['answer'] ?? $pair['respuesta'] ?? '');
                                $table .= '<tr>';
                                $table .= '<td style="padding:8px; border:1px solid #ddd; vertical-align:top;">' . ($pair_idx + 1) . '. ' . $left . '</td>';
                                $table .= '<td style="padding:8px; border:1px solid #ddd; vertical-align:top;">' . chr(65 + $pair_idx) . '. ' . $right . '</td>';
                                $table .= '</tr>';
                            }
                            $table .= '</tbody></table>';
                            echo $table;
                        } else {
                            echo html_writer::tag('div', 'No hay elementos de emparejamiento disponibles.', [
                                'style' => 'border:1px dashed #ccc; padding:12px; border-radius:8px; color:#999; font-size:12px;'
                            ]);
                        }
                    } else if (self::is_lacunar_type($type)) {
                        $real_answer = $item['short_answer'] ?? $item['cloze_answer'] ?? '';
                        $question_text = $item['consigna'] ?? $item['consiga'] ?? $item['text'] ?? '';
                        if (self::should_show_lacunar_answer($real_answer, $question_text)) {
                            echo html_writer::tag('div', 'Respuesta esperada: ' . s($real_answer), [
                                'class' => 'cloze-answer-preview-label',
                                'style' => 'font-size:12px; color:#666; margin-top:8px;'
                            ]);
                        }
                    } else if (strpos($type, 'breve') !== false || strpos($type, 'clásica') !== false || $type === 'shortanswer') {
                        if (!empty($item['short_answer'])) {
                            echo html_writer::tag('div', '✅ Respuesta esperada: ' . s($item['short_answer']), [
                                'class' => 'shortanswer-answer-preview-label',
                                'style' => 'font-size:12px; color:#1e8e3e; margin-top:8px; background:#e6f4ea; padding:8px 12px; border-radius:6px;'
                            ]);
                        }
                    } else if (strpos($type, 'abierta') !== false || strpos($type, 'ensayo') !== false) {
                        // Open box placeholder
                        echo html_writer::tag('div', 'El estudiante redactará su respuesta aquí...', [
                            'style' => 'border:1px dashed #ccc; padding:15px; border-radius:8px; color:#999; font-style:italic; font-size:12px;'
                        ]);
                    } else {
                        // Default Fallback
                        if (!empty($item['alternativas'])) {
                            foreach ($item['alternativas'] as $opt) {
                                echo html_writer::tag('div', "• " . s($opt), ['style' => 'font-size:13px; color:#555; margin-bottom:4px;']);
                            }
                        }
                    }
                    if ($show_rich_content) {
                        echo html_writer::end_tag('div');
                    }
                    
                    // Objectives and Meta
                    echo html_writer::start_tag('div', [
                        'class' => 'item-objectives-container',
                        'id' => 'item-objectives-' . $index,
                        'style' => 'font-size:11px; color:#777; margin-top:10px;'
                    ]);
                    if (!empty($item['objectives'])) {
                        $formatted_objectives = array_map(function($obj) use ($objective_map) {
                            if (isset($objective_map[$obj])) {
                                $bloom = $objective_map[$obj]['bloom'];
                                $text = $objective_map[$obj]['text'];
                                return $bloom ? "<li><b>{$bloom}</b> {$text}" : $text;
                            }
                            return $obj;
                        }, $item['objectives']);
                        
                        echo '🎯 <strong>Objetivos:</strong><ul> ' . implode('; ', $formatted_objectives) . '</ul>';
                    }
                    echo html_writer::end_tag('div');
 
                    // --- PER-ITEM ADJUSTMENT UI ---
                    echo html_writer::start_tag('div', ['style' => 'margin-top:15px; border-top:1px solid #f0f0f0; padding-top:10px; display:flex; gap:10px; align-items:center;']);
                    echo html_writer::tag('button', 'Editar ✏️', [
                        'type' => 'button',
                        'class' => 'areteia-btn item-edit-btn',
                        'data-index' => $index,
                        'style' => 'font-size:11px; padding:2px 8px; border-color:#6c63ff; color:#6c63ff;'
                    ]);
                    echo html_writer::tag('button', 'Ajustar con IA ✨', [
                        'type' => 'button',
                        'class' => 'item-adjust-trigger',
                        'data-index' => $index
                    ]);
                    echo html_writer::end_tag('div');
                    
                    echo html_writer::start_tag('div', [
                        'class' => 'item-adjust-tray',
                        'data-index' => $index
                    ]);
                    echo html_writer::tag('textarea', '', [
                        'class' => 'item-adjust-textarea',
                        'placeholder' => 'Ej: Hazla más difícil, cambia el enfoque a la praxis, agrega más alternativas...'
                    ]);
                    echo html_writer::start_tag('div', ['style' => 'display:flex; gap:8px; align-items:center; margin-top:8px;']);
                    echo html_writer::tag('button', '✨ Enviar ajuste a IA', [
                        'type' => 'button',
                        'class' => 'areteia-btn areteia-btn-primary item-adjust-submit-btn',
                        'data-index' => $index,
                        'style' => 'font-size:11px; padding:5px 14px;',
                    ]);
                    echo html_writer::tag('span', '', [
                        'class' => 'item-adjust-status',
                        'data-index' => $index,
                        'style' => 'font-size:11px; color:#666;',
                    ]);
                    echo html_writer::end_tag('div');
                    echo html_writer::end_tag('div');
                    
                    echo html_writer::end_tag('div'); // end .item-view-mode
                    
                    // Edit Mode Container
                    echo html_writer::start_tag('div', ['class' => 'item-edit-mode', 'style' => 'margin-top:10px;']);

                    // Edit Text / Consigna
                    echo html_writer::tag('label', 'Pregunta / Consigna:', ['style' => 'font-weight:bold; font-size:12px; display:block; margin-bottom:5px;']);
                    $question_source = $item['consigna'] ?? $item['consiga'] ?? $item['text'] ?? '';
                    echo html_writer::tag('textarea', s($question_source), [
                        'class' => 'edit-item-consigna form-control',
                        'style' => 'width:100%; min-height:80px; margin-bottom:10px; font-size:13px;'
                    ]);

                    // Edit Difficulty and Points (flex row)
                    echo html_writer::start_tag('div', ['style' => 'display:flex; gap:15px; margin-bottom:10px;']);
                    // Difficulty
                    echo html_writer::start_tag('div', ['style' => 'flex:1;']);
                    echo html_writer::tag('label', 'Dificultad:', ['style' => 'font-weight:bold; font-size:12px; display:block; margin-bottom:5px;']);
                    echo html_writer::start_tag('select', ['class' => 'edit-item-difficulty form-control', 'style' => 'font-size:13px;']);
                    foreach (['Fácil', 'Media', 'Difícil'] as $d_opt) {
                        $sel = ($d_opt === ($item['difficulty'] ?? 'Media')) ? ['selected' => 'selected'] : [];
                        echo html_writer::tag('option', $d_opt, array_merge(['value' => $d_opt], $sel));
                    }
                    echo html_writer::end_tag('select');
                    echo html_writer::end_tag('div');

                    // Points
                    echo html_writer::start_tag('div', ['style' => 'flex:1;']);
                    echo html_writer::tag('label', 'Puntos:', ['style' => 'font-weight:bold; font-size:12px; display:block; margin-bottom:5px;']);
                    echo html_writer::empty_tag('input', [
                        'type' => 'number',
                        'class' => 'edit-item-points form-control',
                        'style' => 'font-size:13px;',
                        'value' => $item['points'] ?? 1.0,
                        'step' => '0.5',
                        'min' => '0'
                    ]);
                    echo html_writer::end_tag('div');
                    echo html_writer::end_tag('div'); // end flex row

                    // Type-specific controls
                    if (strpos($type, 'múltiple') !== false || strpos($type, 'selección') !== false || strpos($type, 'cerrada') !== false) {
                        echo html_writer::tag('label', 'Alternativas (marca la correcta):', ['style' => 'font-weight:bold; font-size:12px; display:block; margin-bottom:5px;']);
                        echo html_writer::start_tag('div', ['class' => 'edit-item-alternativas-container', 'style' => 'display:flex; flex-direction:column; gap:8px; margin-bottom:10px;']);
                        $correct_index = isset($item['correct_index']) ? (int)$item['correct_index'] : 0;
                        foreach (($item['alternativas'] ?? []) as $opt_idx => $opt) {
                            echo html_writer::start_tag('div', ['style' => 'display:flex; gap:8px; align-items:center;']);
                            // Radio Button
                            $checked = ($opt_idx === $correct_index) ? ['checked' => 'checked'] : [];
                            echo html_writer::empty_tag('input', array_merge([
                                'type' => 'radio',
                                'name' => "edit_correct_index_{$index}",
                                'value' => $opt_idx,
                                'style' => 'transform:scale(1.2);'
                            ], $checked));
                            // Input text
                            echo html_writer::empty_tag('input', [
                                'type' => 'text',
                                'class' => 'edit-item-alternativa form-control',
                                'style' => 'font-size:13px; flex-grow:1;',
                                'value' => $opt
                            ]);
                            echo html_writer::end_tag('div');
                        }
                        echo html_writer::end_tag('div');
                    } else if (strpos($type, 'verdadero') !== false) {
                        echo html_writer::tag('label', 'Respuesta correcta:', ['style' => 'font-weight:bold; font-size:12px; display:block; margin-bottom:5px;']);
                        $correct_bool = isset($item['correct_boolean']) ? (bool)$item['correct_boolean'] : true;
                        echo html_writer::start_tag('div', ['style' => 'display:flex; gap:15px; margin-bottom:10px;']);
                        echo html_writer::start_tag('label', ['style' => 'font-size:13px; cursor:pointer;']);
                        $checked_t = $correct_bool ? ['checked' => 'checked'] : [];
                        echo html_writer::empty_tag('input', array_merge([
                            'type' => 'radio',
                            'name' => "edit_correct_bool_{$index}",
                            'value' => 'true',
                            'style' => 'margin-right:5px;'
                        ], $checked_t));
                        echo 'Verdadero';
                        echo html_writer::end_tag('label');
                        
                        echo html_writer::start_tag('label', ['style' => 'font-size:13px; cursor:pointer;']);
                        $checked_f = !$correct_bool ? ['checked' => 'checked'] : [];
                        echo html_writer::empty_tag('input', array_merge([
                            'type' => 'radio',
                            'name' => "edit_correct_bool_{$index}",
                            'value' => 'false',
                            'style' => 'margin-right:5px;'
                        ], $checked_f));
                        echo 'Falso';
                        echo html_writer::end_tag('label');
                        echo html_writer::end_tag('div');
                    } else if (strpos($type, 'match') !== false || strpos($type, 'emparejamiento') !== false || strpos($type, 'orden') !== false) {
                        echo html_writer::tag('label', 'Parejas (Premisa y Respuesta):', ['style' => 'font-weight:bold; font-size:12px; display:block; margin-bottom:5px;']);
                        echo html_writer::start_tag('div', ['class' => 'edit-item-pairs-container', 'style' => 'display:flex; flex-direction:column; gap:8px; margin-bottom:10px;']);
                        foreach (($item['pairs'] ?? []) as $pair_idx => $pair) {
                            $left = $pair['premise'] ?? $pair['premisa'] ?? '';
                            $right = $pair['answer'] ?? $pair['respuesta'] ?? '';
                            echo html_writer::start_tag('div', ['style' => 'display:flex; gap:8px;']);
                            echo html_writer::empty_tag('input', [
                                'type' => 'text',
                                'class' => 'edit-item-pair-premise form-control',
                                'placeholder' => 'Premisa',
                                'style' => 'font-size:13px; flex:1;',
                                'value' => $left
                            ]);
                            echo html_writer::empty_tag('input', [
                                'type' => 'text',
                                'class' => 'edit-item-pair-answer form-control',
                                'placeholder' => 'Respuesta',
                                'style' => 'font-size:13px; flex:1;',
                                'value' => $right
                            ]);
                            echo html_writer::end_tag('div');
                        }
                        echo html_writer::end_tag('div');
                    } else if (self::is_lacunar_type($type) || strpos($type, 'breve') !== false || $type === 'shortanswer' || strpos($type, 'numérica') !== false) {
                        echo html_writer::tag('label', 'Respuesta correcta esperada:', ['style' => 'font-weight:bold; font-size:12px; display:block; margin-bottom:5px;']);
                        $real_answer = $item['short_answer'] ?? $item['cloze_answer'] ?? $item['numerical_value'] ?? '';
                        echo html_writer::empty_tag('input', [
                            'type' => 'text',
                            'class' => 'edit-item-correct-answer form-control',
                            'style' => 'font-size:13px; margin-bottom:10px;',
                            'value' => $real_answer
                        ]);
                    }

                    // Save / Cancel Actions
                    echo html_writer::start_tag('div', ['style' => 'display:flex; gap:10px; margin-top:15px;']);
                    echo html_writer::tag('button', '💾 Guardar', [
                        'type' => 'button',
                        'class' => 'areteia-btn areteia-btn-primary item-save-btn',
                        'data-index' => $index,
                        'style' => 'padding:6px 15px; font-size:12px;'
                    ]);
                    echo html_writer::tag('button', 'Cancelar', [
                        'type' => 'button',
                        'class' => 'areteia-btn item-cancel-btn',
                        'data-index' => $index,
                        'style' => 'padding:6px 15px; font-size:12px;'
                    ]);
                    echo html_writer::end_tag('div');

                    echo html_writer::end_tag('div'); // end .item-edit-mode
                    
                    echo html_writer::end_tag('div'); // end flex-grow:1 diver::end_tag('div'); // body div
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
                    'value' => json_encode($data)
                ]);
                
                echo html_writer::end_tag('form');
            }

            // Inner nav
            echo html_writer::start_tag('div', [
                'class' => 'areteia-nav',
                'style' => 'margin-top:20px; border-top:1px solid #eee; padding-top:15px;',
            ]);
            echo html_writer::link(
                new moodle_url($PAGE->url, array_merge($link_params, ['step' => 1])),
                '← Volver',
                ['class' => 'areteia-btn']
            );

            echo html_writer::tag('button', 'Configurar Evaluación Final →', [
                'id'    => 'btn-go-to-step3',
                'type'  => 'submit',
                'form'  => 'item-selection-form',
                'class' => 'areteia-btn areteia-btn-primary',
                'style' => 'background:#185fa5; border-color:#185fa5;'
            ]);
            
            echo html_writer::end_tag('div');
        }
    }

    private static function render_lacunar_preview(string $text, string $answer = ''): string {
        $question = trim($text);
        if ($question === '') {
            return '<em>Texto lacunar sin consignas.</em>';
        }

        $blank = '<span style="display:inline-block; min-width:140px; border-bottom:1px solid #999; padding:0 4px; margin:0 2px; line-height:1.4;">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>';
        $safe_question = s($question);

        // Prioritize explicit blank markers (underscores or bracket placeholders) only when they clearly represent blanks.
        if (preg_match('/_{3,}/u', $question)) {
            return preg_replace('/_{3,}/u', $blank, $safe_question);
        }

        if (preg_match('/\[\s*(?:_+|\.{3,})\s*\]/u', $question)) {
            return preg_replace('/\[\s*(?:_+|\.{3,})\s*\]/u', $blank, $safe_question);
        }

        // If the answer appears naturally in the question, hide it with a blank.
        if (!empty($answer) && stripos($question, $answer) !== false) {
            $escaped_answer = preg_quote($answer, '/');
            $pattern = '/(' . $escaped_answer . ')/iu';
            return preg_replace($pattern, $blank, $safe_question, 1);
        }

        // Otherwise preserve bracketed option lists and append a generic blank at the end.
        return $safe_question . ' ' . $blank;
    }

    private static function is_lacunar_type(string $type): bool {
        return strpos($type, 'lacunar') !== false
            || strpos($type, 'lacun') !== false
            || strpos($type, 'cloze') !== false
            || $type === 'multianswer';
    }

    private static function should_show_lacunar_answer(string $answer, string $question): bool {
        $answer = trim($answer);
        $question = trim($question);

        if ($answer === '' || $answer === $question) {
            return false;
        }

        if (strlen($answer) > strlen($question) * 0.5) {
            return false;
        }

        if (str_word_count($answer) > 8) {
            return false;
        }

        return true;
    }
}
