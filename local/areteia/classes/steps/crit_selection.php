<?php
namespace local_areteia\steps;

defined('MOODLE_INTERNAL') || die();

use html_writer;
use moodle_url;
use local_areteia\session_manager;
use local_areteia\step_renderer;
use local_areteia\rag_client;
use local_areteia\encaje_table;

/**
 * crit_selection — Selección y generación del instrumento de corrección (Action: crit, Step: 0).
 */
class crit_selection {

    public static function render(array $ctx): void {
        global $PAGE, $OUTPUT;

        $id = $ctx['id'];
        $instrument = session_manager::get('instrument', '');
        $correction = session_manager::get('correction_instrument', '');
        $correction_content = session_manager::get('correction_content', '');
        $do_gen = optional_param('do_gen', 0, PARAM_INT);
        $change_corr = optional_param('change_corr', 0, PARAM_INT);
        $link_params = ['id' => $id, 'action' => 'crit'];

        // Guard: require an evaluation instrument to exist
        if (empty($instrument)) {
            echo html_writer::tag('p', 'Selecciona el instrumento de corrección', ['class' => 'areteia-stitle']);
            echo html_writer::start_tag('div', ['class' => 'alert alert-warning', 'style' => 'margin-top:20px;']);
            echo html_writer::tag('strong', '⚠️ No se encontró un instrumento de evaluación.');
            echo html_writer::tag('p', 'Primero debes completar el flujo "📝 Crear evaluación" para definir tu instrumento y sus ítems.', ['style' => 'margin-top:8px;']);
            $eval_url = new moodle_url($PAGE->url, ['action' => 'eval', 'step' => 3]);
            echo html_writer::link($eval_url, 'Ir a Crear evaluación →', ['class' => 'areteia-btn areteia-btn-primary', 'style' => 'margin-top:10px;']);
            echo html_writer::end_tag('div');
            step_renderer::render_nav(0);
            return;
        }

        // Allow user to re-select correction instrument
        if ($change_corr) {
            session_manager::unset_key('correction_instrument');
            session_manager::unset_key('correction_content');
            $correction = '';
            $correction_content = '';
        }

        echo html_writer::tag('p', 'Selecciona y genera el instrumento de corrección', ['class' => 'areteia-stitle']);

        // Summary card of the evaluation instrument
        echo html_writer::start_tag('div', [
            'class' => 'areteia-card',
            'style' => 'background:#f0f4ff; border:1px solid #d0d8f0; padding:15px; margin-bottom:20px;'
        ]);
        echo html_writer::tag('strong', '📝 Instrumento de evaluación seleccionado:', ['style' => 'display:block; font-size:12px; color:#555; margin-bottom:5px;']);
        echo html_writer::tag('div', $instrument, ['style' => 'font-size:16px; font-weight:700; color:#185fa5;']);

        // Show objectives if available
        $d2 = session_manager::get('d2', '');

        echo html_writer::end_tag('div');

        step_renderer::render_rag_info();

        // ---------------------------------------------------------------
        // Phase 1: Selection (if no correction instrument chosen yet)
        // ---------------------------------------------------------------
        if (empty($correction)) {
            $options = encaje_table::get_correction_options($instrument);
            $all_types = array_keys(encaje_table::LABELS);

            echo html_writer::tag('p',
                'Según el tipo de evaluación elegido, estos son los instrumentos de corrección pedagógicamente adecuados:',
                ['class' => 'areteia-sdesc']
            );

            echo html_writer::start_tag('div', ['style' => 'display:flex; flex-direction:column; gap:12px; margin-bottom:20px;']);

            $valid_keys = array_column($options, 'key');

            foreach ($all_types as $type_key) {
                $is_valid = in_array($type_key, $valid_keys);
                $label = encaje_table::LABELS[$type_key];
                $icon = encaje_table::ICONS[$type_key];
                $desc = encaje_table::DESCRIPTIONS[$type_key];

                $card_style = $is_valid
                    ? 'background:#fff; border:2px solid #e0e0e0; padding:18px; border-radius:12px; cursor:pointer; transition:all 0.2s;'
                    : 'background:#f5f5f5; border:2px solid #eee; padding:18px; border-radius:12px; opacity:0.45; cursor:not-allowed;';

                if ($is_valid) {
                    $select_url = new moodle_url($PAGE->url, array_merge($link_params, [
                        'step' => 0,
                        'correction_instrument' => $type_key,
                        'do_gen' => 1
                    ]));
                    echo html_writer::start_tag('a', [
                        'href' => $select_url->out(false),
                        'class' => 'areteia-btn areteia-btn-primary sug-card',
                        'data-ia' => '1',
                        'style' => $card_style . ' text-decoration:none; color:inherit; display:block;',
                    ]);
                } else {
                    echo html_writer::start_tag('div', [
                        'style' => $card_style,
                        'title' => 'No disponible para este tipo de evaluación',
                    ]);
                }

                echo html_writer::start_tag('div', ['style' => 'display:flex; align-items:flex-start; gap:12px;']);
                echo html_writer::tag('span', $icon, ['style' => 'font-size:28px; line-height:1;']);
                echo html_writer::start_tag('div', ['style' => 'flex:1;']);
                echo html_writer::tag('div', $label, ['style' => 'font-weight:700; font-size:15px; color:#185fa5; margin-bottom:4px;']);
                echo html_writer::tag('div', $desc, ['style' => 'font-size:12px; color:#666; line-height:1.5;']);
                if (!$is_valid) {
                    echo html_writer::tag('div', '🚫 No aplicable para ' . $instrument, [
                        'style' => 'font-size:11px; color:#999; margin-top:6px; font-style:italic;'
                    ]);
                }
                echo html_writer::end_tag('div');
                echo html_writer::end_tag('div');

                echo html_writer::end_tag($is_valid ? 'a' : 'div');
            }

            echo html_writer::end_tag('div');

            step_renderer::render_nav(0);
            return;
        }

        // ---------------------------------------------------------------
        // Phase 2: Generation + Refinement
        // ---------------------------------------------------------------

        // Generate if requested
        if ($do_gen || empty($correction_content)) {
            $feedback = optional_param('feedback', '', PARAM_TEXT);
            $inst_content = session_manager::get('inst_content', '');
            $final_json = session_manager::get('final_selection_json', '');

            $res_data = rag_client::generate([
                'course_id'            => $id,
                'course_title'         => $PAGE->course->fullname,
                'step'                 => 9,
                'objective'            => $d2,
                'objective_json'       => session_manager::get('d2_json', ''),
                'd1_content'           => session_manager::get('d1', ''),
                'd3_function'          => session_manager::get('d3', ''),
                'd4_modality'          => session_manager::get('d4', ''),
                'chosen_instrument'    => $instrument,
                'correction_type'      => $correction,
                'correction_label'     => encaje_table::LABELS[$correction] ?? $correction,
                'instrument_content'   => $inst_content,
                'quiz_items_json'      => $final_json,
                'feedback'             => $feedback,
            ]);

            if ($res_data && $res_data->status == 'success') {
                $correction_content = json_encode($res_data->output);
                session_manager::set('correction_content', $correction_content);

                if (!empty($res_data->usage)) {
                    session_manager::set('s9_usage', (array)$res_data->usage);
                }

                // PRG: redirect to avoid re-generation on reload
                $clean_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 0]));
                redirect($clean_url);
            } else {
                $err = $res_data->message ?? 'Error al generar el instrumento de corrección.';
                echo $OUTPUT->notification('Error de IA: ' . $err, 'error');
            }
        }

        // Display the generated correction instrument
        $corr_label = encaje_table::LABELS[$correction] ?? $correction;
        $corr_icon = encaje_table::ICONS[$correction] ?? '📄';

        echo html_writer::start_tag('div', [
            'class' => 'areteia-card',
            'style' => 'background:#f8fff8; border:1px solid #c8e6c9; padding:15px; margin-bottom:20px;'
        ]);
        echo html_writer::tag('div', "$corr_icon $corr_label", [
            'style' => 'font-size:16px; font-weight:700; color:#2e7d32; margin-bottom:10px;'
        ]);

        if (!empty($correction_content)) {
            $data = json_decode($correction_content, true);
            if (is_array($data)) {
                // Call static renderer in crit_finalize class
                crit_finalize::render_correction_public($correction, $data);
            } else {
                echo html_writer::tag('div', 'Error decodificando la respuesta de la IA.', ['class' => 'alert alert-danger']);
            }
        } else {
            echo html_writer::tag('div', 'No se pudo generar el instrumento. Intenta nuevamente.', ['class' => 'alert alert-warning']);
        }

        echo html_writer::end_tag('div');

        // Feedback area for refinement
        echo html_writer::start_tag('div', ['class' => 'areteia-card', 'style' => 'background:#fffcf5; border:1px solid #faeeda; padding:15px; margin-bottom:20px;']);
        echo html_writer::tag('strong', '✨ ¿Deseas ajustar este instrumento? Pide un cambio a AreteIA:', ['style' => 'display:block; margin-bottom:10px; font-size:12px; color:#854f0b;']);
        echo html_writer::tag('textarea', '', [
            'name' => 'feedback',
            'class' => 'form-control w-100 mb-2',
            'placeholder' => 'Ej: Agrega más criterios, simplifica los descriptores, enfócate en la práctica...',
            'rows' => 2
        ]);
        echo html_writer::start_tag('div', ['style' => 'display:flex; gap:10px; align-items:center;']);
        echo step_renderer::render_preview_button(9);
        $adjust_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 0, 'do_gen' => 1]));
        echo html_writer::link($adjust_url, 'Refinar Instrumento ✨', [
            'class' => 'areteia-btn areteia-btn-primary',
            'style' => 'font-size:12px; background:#854f0b; border-color:#854f0b;',
            'data-adjust' => '1'
        ]);
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');

        // Change correction instrument button
        $change_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 0, 'change_corr' => 1]));
        echo html_writer::link($change_url, '🔄 Cambiar instrumento de corrección', [
            'class' => 'areteia-btn',
            'style' => 'font-size:12px; margin-bottom:20px; display:inline-block;'
        ]);

        // Navigation
        $next_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 1]));
        step_renderer::render_nav(0, null, $next_url, 'Ver resultado final →');
    }
}
