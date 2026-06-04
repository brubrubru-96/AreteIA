<?php
namespace local_areteia;

defined('MOODLE_INTERNAL') || die();

class docx_generator {

    public static function generate_student_docx(array $items, string $title, string $course_name, string $scenario = ''): string {
        $total_points = 0;
        foreach ($items as $item) {
            $total_points += (float)($item['points'] ?? 0);
        }

        $paragraphs = [];
        $paragraphs[] = ['text' => $title, 'bold' => true];
        $paragraphs[] = ['text' => ''];
        $paragraphs[] = ['text' => 'Puntos totales: ' . round($total_points, 2)];
        $paragraphs[] = ['text' => ''];
        $paragraphs[] = ['text' => 'Instrucciones: Responde todas las preguntas con cuidado. Muestra todo tu procedimiento cuando corresponda.'];
        $paragraphs[] = ['text' => ''];

        if (!empty($scenario)) {
            $paragraphs[] = ['text' => 'Escenario / Contexto', 'bold' => true];
            $paragraphs[] = ['text' => $scenario];
            $paragraphs[] = ['text' => ''];
        }

        foreach ($items as $index => $item) {
            $item_num = $index + 1;
            $points = round((float)($item['points'] ?? 0), 2);
            $paragraphs[] = ['text' => "Pregunta {$item_num} - {$points} pts", 'bold' => true];

            $text = $item['text'] ?? $item['consigna'] ?? $item['consiga'] ?? '';
            $type = strtolower($item['type'] ?? 'essay');
            if ($type === 'multianswer' || strpos($type, 'cloze') !== false || strpos($type, 'lacunar') !== false) {
                $text = self::render_cloze_text($text, $item['cloze_answer'] ?? $item['correct'] ?? $item['short_answer'] ?? '');
            }
            $paragraphs[] = ['text' => $text];

            if (in_array($type, ['multichoice', 'selección', 'múltiple'], true)) {
                $options = $item['options'] ?? [];
                foreach ($options as $opt_idx => $opt) {
                    $letter = chr(65 + $opt_idx);
                    $paragraphs[] = ['text' => "{$letter}) {$opt}"];
                }
            } elseif (in_array($type, ['truefalse', 'verdadero'], true)) {
                $paragraphs[] = ['text' => 'T) Verdadero'];
                $paragraphs[] = ['text' => 'F) Falso'];
            } elseif ($type === 'match') {
                $pairs = $item['pairs'] ?? [];
                if (!empty($pairs)) {
                    $rows = [];
                    foreach ($pairs as $pair_idx => $pair) {
                        $rows[] = [
                            ($pair_idx + 1) . '. ' . ($pair['premise'] ?? ''),
                            chr(65 + $pair_idx) . '. ' . ($pair['answer'] ?? '')
                        ];
                    }
                    $paragraphs[] = ['table' => ['headers' => ['Premisa', 'Respuesta'], 'rows' => $rows]];
                }
            }

            $paragraphs[] = ['text' => ''];
            $paragraphs[] = ['text' => ''];
            $paragraphs[] = ['text' => ''];
        }

        return self::build_docx($paragraphs);
    }

    public static function generate_teacher_docx(array $items, string $title, string $course_name, string $justification, array $objectives_data, string $scenario = ''): string {
        $total_points = 0;
        foreach ($items as $item) {
            $total_points += (float)($item['points'] ?? 0);
        }

        $paragraphs = [];
        $paragraphs[] = ['text' => $title, 'bold' => true];
        $paragraphs[] = ['text' => ''];
        $paragraphs[] = ['text' => 'Puntos totales: ' . round($total_points, 2)];
        $paragraphs[] = ['text' => 'Total de ítems: ' . count($items)];
        $paragraphs[] = ['text' => ''];

        /* Justificación pedagógica - temporalmente oculta
        if (!empty($justification)) {
            $paragraphs[] = ['text' => 'Justificación pedagógica:', 'bold' => true];
            $paragraphs[] = ['text' => $justification];
            $paragraphs[] = ['text' => ''];
        }
        */

        if (!empty($scenario)) {
            $paragraphs[] = ['text' => 'Escenario / Contexto', 'bold' => true];
            $paragraphs[] = ['text' => $scenario];
            $paragraphs[] = ['text' => ''];
        }

        if (!empty($objectives_data)) {
            $paragraphs[] = ['text' => 'Objetivos de aprendizaje:', 'bold' => true];
            foreach ($objectives_data as $obj) {
                $bloom = $obj['bloom'] ?? '';
                $text = $obj['text'] ?? '';
                $prefix = $bloom ? "{$bloom}: " : '';
                $paragraphs[] = ['text' => "• {$prefix}{$text}"];
            }
            $paragraphs[] = ['text' => ''];
        }

        $objective_map = [];
        foreach ($objectives_data as $idx => $obj) {
            if (!empty($obj['text'])) {
                $objective_map['Obj ' . ($idx + 1)] = $obj;
            }
        }

        foreach ($items as $index => $item) {
            $item_num = $index + 1;
            $points = round((float)($item['points'] ?? 0), 2);
            $paragraphs[] = ['text' => "Pregunta {$item_num} - {$points} pts", 'bold' => true];

            $text = $item['text'] ?? $item['consigna'] ?? $item['consiga'] ?? '';
            $paragraphs[] = ['text' => $text];

            $type = strtolower($item['type'] ?? 'essay');
            if (in_array($type, ['multichoice', 'selección', 'múltiple'], true)) {
                $options = $item['options'] ?? [];
                foreach ($options as $opt_idx => $opt) {
                    $letter = chr(65 + $opt_idx);
                    $is_correct = isset($item['correct']) && $item['correct'] == $opt_idx;
                    $marker = $is_correct ? '✓ ' : '  ';
                    $paragraphs[] = ['text' => "{$letter}) {$marker}{$opt}"];
                }
            } elseif (in_array($type, ['truefalse', 'verdadero'], true)) {
                $correct = $item['correct'] ?? true;
                $t_correct = $correct ? '✓ ' : '  ';
                $f_correct = !$correct ? '✓ ' : '  ';
                $paragraphs[] = ['text' => "T) {$t_correct}Verdadero"];
                $paragraphs[] = ['text' => "F) {$f_correct}Falso"];
            } elseif ($type === 'match') {
                $pairs = $item['pairs'] ?? [];
                if (!empty($pairs)) {
                    $rows = [];
                    foreach ($pairs as $pair_idx => $pair) {
                        $rows[] = [
                            ($pair_idx + 1) . '. ' . ($pair['premise'] ?? ''),
                            chr(65 + $pair_idx) . '. ' . ($pair['answer'] ?? '')
                        ];
                    }
                    $paragraphs[] = ['table' => ['headers' => ['Premisa', 'Respuesta'], 'rows' => $rows]];
                }
            } elseif ($type === 'multianswer' || strpos($type, 'cloze') !== false || strpos($type, 'lacunar') !== false) {
                $correct = $item['cloze_answer'] ?? $item['correct'] ?? $item['short_answer'] ?? '';
                $paragraphs[] = ['text' => 'Respuesta esperada:', 'bold' => true];
                $paragraphs[] = ['text' => $correct];
            } elseif (in_array($type, ['shortanswer', 'breve', 'clásica'], true)) {
                $correct = $item['correct'] ?? '';
                $paragraphs[] = ['text' => 'Respuesta esperada:', 'bold' => true];
                $paragraphs[] = ['text' => $correct];
            } elseif (in_array($type, ['numerical', 'numérica'], true)) {
                $correct = $item['correct'] ?? 0;
                $paragraphs[] = ['text' => 'Respuesta esperada:', 'bold' => true];
                $paragraphs[] = ['text' => (string)$correct];
            }

            if (!empty($item['objectives'])) {
                $paragraphs[] = ['text' => 'Objetivos de aprendizaje:', 'bold' => true];
                foreach ($item['objectives'] as $obj_ref) {
                    if (isset($objective_map[$obj_ref])) {
                        $obj = $objective_map[$obj_ref];
                        $bloom = $obj['bloom'] ?? '';
                        $text = $obj['text'] ?? $obj_ref;
                        $prefix = $bloom ? "{$bloom}: " : '';
                        $paragraphs[] = ['text' => "• {$prefix}{$text}"];
                    } else {
                        $paragraphs[] = ['text' => "• {$obj_ref}"];
                    }
                }
            }

            $paragraphs[] = ['text' => ''];
            $paragraphs[] = ['text' => ''];
        }

        return self::build_docx($paragraphs);
    }

    private static function render_cloze_text(string $text, string $answer = ''): string {
        $rendered = trim($text);
        $answer = trim($answer);

        if ($rendered === '' || $answer === '') {
            return $rendered;
        }

        if (!preg_match('/_{3,}|\[[^\]]*\]/u', $rendered) && stripos($rendered, $answer) !== false) {
            return preg_replace('/' . preg_quote($answer, '/') . '/iu', '__________', $rendered, 1);
        }

        $rendered = preg_replace('/_{3,}/u', '__________', $rendered);
        $rendered = preg_replace('/\[[^\]]*\]/u', '__________', $rendered);

        if ($rendered === trim($text)) {
            $rendered .= ' __________';
        }

        return $rendered;
    }

    private static function build_docx(array $paragraphs): string {
        if (!class_exists('ZipArchive')) {
            throw new \moodle_exception('ziparchive_missing', 'local_areteia', '', null, 'PHP ZipArchive extension is required to generate DOCX files.');
        }

        $document_xml = self::build_document_xml($paragraphs);

        $content_types = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>';

        $rels = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';

        $document_rels = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';

        $tmpdir = sys_get_temp_dir();
        if (empty($tmpdir) || !is_dir($tmpdir) || !is_writable($tmpdir)) {
            $tmpdir = '/tmp';
        }
        if (empty($tmpdir) || !is_dir($tmpdir) || !is_writable($tmpdir)) {
            $tmpdir = '/var/tmp';
        }
        if (empty($tmpdir) || !is_dir($tmpdir) || !is_writable($tmpdir)) {
            $tmpdir = dirname(__DIR__);
        }

        $tmpname = tempnam($tmpdir, 'docx_');
        if ($tmpname === false || $tmpname === '') {
            $tmpname = $tmpdir . '/docx_' . bin2hex(random_bytes(8));
        }

        $tmpname = (string)$tmpname;
        if (empty($tmpname)) {
            throw new \moodle_exception('cannot_create_docx', 'local_areteia', '', null, 'No se pudo generar un nombre de archivo temporal para el DOCX.');
        }

        $handle = @fopen($tmpname, 'w');
        if ($handle === false) {
            throw new \moodle_exception('cannot_create_docx', 'local_areteia', '', null, 'No se pudo crear el archivo temporal para el DOCX.');
        }
        fclose($handle);

        $zip = new \ZipArchive();
        $result = @$zip->open($tmpname, \ZipArchive::CREATE);
        if ($result !== true) {
            @unlink($tmpname);
            throw new \moodle_exception('cannot_create_docx', 'local_areteia', '', null, 'No se pudo abrir el archivo DOCX temporal.');
        }

        $zip->addFromString('[Content_Types].xml', $content_types);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('word/document.xml', $document_xml);
        $zip->addFromString('word/_rels/document.xml.rels', $document_rels);
        $zip->close();

        $content = file_get_contents($tmpname);
        @unlink($tmpname);

        return $content;
    }

    private static function build_document_xml(array $paragraphs): string {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">';
        $xml .= '<w:body>';

        foreach ($paragraphs as $paragraph) {
            if (!empty($paragraph['table']) && is_array($paragraph['table'])) {
                $xml .= self::build_table_xml($paragraph['table']);
                continue;
            }

            $text = $paragraph['text'] ?? '';
            $bold = !empty($paragraph['bold']);

            if ($text === '') {
                $xml .= '<w:p/>';
                continue;
            }

            $lines = preg_split('/\r\n|\r|\n/', $text);
            foreach ($lines as $line) {
                $xml .= '<w:p><w:r>';
                if ($bold) {
                    $xml .= '<w:rPr><w:b/></w:rPr>';
                }
                $xml .= '<w:t xml:space="preserve">' . self::escape_xml($line) . '</w:t>';
                $xml .= '</w:r></w:p>';
            }
        }

        $xml .= '<w:sectPr>'
            . '<w:pgSz w:w="11906" w:h="16838"/>'
            . '<w:pgMar w:top="1800" w:right="1800" w:bottom="1800" w:left="1800" w:header="720" w:footer="720" w:gutter="0"/>'
            . '<w:cols w:space="720"/>'
            . '<w:docGrid w:linePitch="360"/>'
            . '</w:sectPr>';

        $xml .= '</w:body></w:document>';
        return $xml;
    }

    private static function build_table_xml(array $table): string {
        $headers = $table['headers'] ?? [];
        $rows = $table['rows'] ?? [];

        $xml = '<w:tbl>';
        $xml .= '<w:tblPr>';
        $xml .= '<w:tblW w:type="auto" w:w="0"/>';
        $xml .= '<w:tblBorders>';
        $xml .= '<w:top w:val="single" w:sz="4" w:space="0" w:color="auto"/>';
        $xml .= '<w:left w:val="single" w:sz="4" w:space="0" w:color="auto"/>';
        $xml .= '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="auto"/>';
        $xml .= '<w:right w:val="single" w:sz="4" w:space="0" w:color="auto"/>';
        $xml .= '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="auto"/>';
        $xml .= '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="auto"/>';
        $xml .= '</w:tblBorders>';
        $xml .= '</w:tblPr>';

        if (!empty($headers)) {
            $xml .= '<w:tr>';
            foreach ($headers as $header) {
                $xml .= self::build_table_cell($header, true);
            }
            $xml .= '</w:tr>';
        }

        foreach ($rows as $row) {
            $xml .= '<w:tr>';
            foreach ($row as $cell) {
                $xml .= self::build_table_cell((string)$cell, false);
            }
            $xml .= '</w:tr>';
        }

        $xml .= '</w:tbl>';
        return $xml;
    }

    private static function build_table_cell(string $text, bool $bold = false): string {
        $xml = '<w:tc>';
        $xml .= '<w:tcPr><w:tcW w:w="4800" w:type="dxa"/></w:tcPr>';
        $xml .= '<w:p><w:r>';
        if ($bold) {
            $xml .= '<w:rPr><w:b/></w:rPr>';
        }
        $xml .= '<w:t xml:space="preserve">' . self::escape_xml($text) . '</w:t>';
        $xml .= '</w:r></w:p>';
        $xml .= '</w:tc>';
        return $xml;
    }

    private static function escape_xml(string $text): string {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Generate a teacher-facing DOCX for the correction instrument (apoyo a la calificación).
     *
     * @param string $correction_type  One of: clave_correccion, lista_cotejo, escala_valoracion, rubrica
     * @param array  $data             Decoded correction_content from session
     * @param string $instrument       Instrument name
     * @param string $course_name      Course name
     * @return string DOCX binary
     */
    public static function generate_correction_docx(string $correction_type, array $data, string $instrument, string $course_name): string {
        $labels = [
            'clave_correccion'  => 'Clave de Corrección',
            'lista_cotejo'      => 'Lista de Cotejo',
            'escala_valoracion' => 'Escala de Valoración',
            'rubrica'           => 'Rúbrica',
        ];
        $label = $labels[$correction_type] ?? $correction_type;
        $title = $data['title'] ?? ($label . ' — ' . $instrument);

        $paragraphs   = [];
        $paragraphs[] = ['text' => $title, 'bold' => true];
        $paragraphs[] = ['text' => $label . ' · ' . $instrument];
        $paragraphs[] = ['text' => ''];

        switch ($correction_type) {
            case 'clave_correccion':
                $rows = [];
                foreach (($data['items'] ?? []) as $item) {
                    $item = (array)$item;
                    $q    = $item['question'] ?? $item['pregunta'] ?? '';
                    $mas  = $item['model_answers'] ?? null;
                    if (is_array($mas) && !empty($mas)) {
                        $ans = 'Respuesta abierta: ' . implode(' / ', array_map('strval', $mas));
                    } else {
                        $ans = (string)($item['answer'] ?? $item['respuesta'] ?? '');
                    }
                    $rows[] = [$q, $ans];
                }
                $paragraphs[] = ['table' => ['headers' => ['Pregunta / Ítem', 'Respuesta correcta'], 'rows' => $rows]];
                break;

            case 'lista_cotejo':
                $rows = [];
                foreach (($data['criteria'] ?? $data['criterios'] ?? []) as $item) {
                    $item   = (array)$item;
                    $rows[] = [$item['criterion'] ?? $item['criterio'] ?? '', '☐ Logrado', '☐ No logrado'];
                }
                $paragraphs[] = ['table' => ['headers' => ['Criterio', 'Logrado', 'No logrado'], 'rows' => $rows]];
                break;

            case 'escala_valoracion':
                $items  = $data['criteria'] ?? $data['criterios'] ?? [];
                $levels = $data['levels'] ?? $data['niveles'] ?? ['Insuficiente', 'Suficiente', 'Bueno', 'Destacado'];
                $rows   = [];
                foreach ($items as $item) {
                    $item  = (array)$item;
                    $row   = [$item['criterion'] ?? $item['criterio'] ?? ''];
                    foreach ($levels as $lv) { $row[] = '○'; }
                    $rows[] = $row;
                }
                $paragraphs[] = ['table' => ['headers' => array_merge(['Criterio'], $levels), 'rows' => $rows]];
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
                $rows = [];
                foreach ($criteria as $crit) {
                    $crit  = (array)$crit;
                    $cname = $crit['name'] ?? $crit['criterion'] ?? '';
                    if (isset($crit['weight'])) { $cname .= ' (' . $crit['weight'] . '%)'; }
                    $row   = [$cname];
                    foreach (($crit['levels'] ?? []) as $lvl) {
                        $lvl  = (array)$lvl;
                        $desc = $lvl['description'] ?? '';
                        if (isset($lvl['score'])) { $desc .= ' (' . $lvl['score'] . ' pts)'; }
                        $row[] = $desc;
                    }
                    $missing = count($levels) - (count($row) - 1);
                    for ($i = 0; $i < $missing; $i++) { $row[] = '—'; }
                    $rows[] = $row;
                }
                $paragraphs[] = ['table' => ['headers' => array_merge(['Criterio'], $levels), 'rows' => $rows]];
                break;
        }

        $paragraphs[] = ['text' => ''];
        /* Justificación pedagógica - temporalmente oculta
        if (!empty($data['justification'])) {
            $paragraphs[] = ['text' => 'Justificación pedagógica:', 'bold' => true];
            $paragraphs[] = ['text' => $data['justification']];
        }
        */

        return self::build_docx($paragraphs);
    }
}
