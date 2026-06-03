<?php
/**
 * Export formats helper for AreteIA activity logs.
 *
 * @package    local_areteia
 * @copyright  2026 Vicente Astorga
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_areteia;

defined('MOODLE_INTERNAL') || die();

class report_export {

    /**
     * Export log records as CSV.
     *
     * @param array $filters Filter conditions.
     */
    public static function csv(array $filters) {
        global $DB;

        // Disable output buffering.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $where = [];
        $params = [];

        if (!empty($filters['courseid'])) {
            $where[] = 'l.courseid = :courseid';
            $params['courseid'] = $filters['courseid'];
        }
        if (!empty($filters['userid'])) {
            $where[] = 'l.userid = :userid';
            $params['userid'] = $filters['userid'];
        }
        if (!empty($filters['action'])) {
            $where[] = 'l.action = :action';
            $params['action'] = $filters['action'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'l.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['timefrom'])) {
            $where[] = 'l.timecreated >= :timefrom';
            $params['timefrom'] = $filters['timefrom'];
        }
        if (!empty($filters['timeto'])) {
            $where[] = 'l.timecreated <= :timeto';
            $params['timeto'] = $filters['timeto'];
        }

        $wherestr = '';
        if ($where) {
            $wherestr = 'WHERE ' . implode(' AND ', $where);
        }

        $sql = "SELECT l.*, u.firstname, u.lastname, c.fullname AS coursename
                  FROM {local_areteia_log} l
             LEFT JOIN {user} u ON u.id = l.userid
             LEFT JOIN {course} c ON c.id = l.courseid
            {$wherestr}
              ORDER BY l.timecreated DESC";

        $records = $DB->get_recordset_sql($sql, $params);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="reporte_areteia_' . date('Ymd_His') . '.csv"');

        $output = fopen('php://output', 'w');
        // Add BOM for Excel compatibility.
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        // Headers.
        fputcsv($output, [
            'ID Evento',
            'Fecha/Hora',
            'Usuario ID',
            'Usuario Nombre',
            'Curso ID',
            'Curso Nombre',
            'Accion',
            'Paso',
            'Instrumento',
            'Tipo Instrumento',
            'Tokens Entrada',
            'Tokens Salida',
            'Duracion (ms)',
            'Estado',
            'Detalle'
        ]);

        foreach ($records as $row) {
            $username = $row->userid ? ($row->firstname . ' ' . $row->lastname) : 'Sistema';
            $coursename = $row->courseid ? $row->coursename : 'General';
            $actionlabel = activity_logger::get_action_label($row->action);

            fputcsv($output, [
                $row->id,
                date('Y-m-d H:i:s', $row->timecreated),
                $row->userid,
                $username,
                $row->courseid,
                $coursename,
                $actionlabel,
                $row->step !== null ? $row->step : '-',
                $row->instrument,
                $row->instrument_type,
                $row->tokens_input,
                $row->tokens_output,
                $row->duration_ms,
                $row->status,
                $row->detail
            ]);
        }
        $records->close();
        fclose($output);
        exit;
    }

    /**
     * Export log records as PDF.
     *
     * @param array $filters Filter conditions.
     */
    public static function pdf(array $filters) {
        global $CFG, $DB;

        // Disable output buffering.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $where = [];
        $params = [];

        if (!empty($filters['courseid'])) {
            $where[] = 'l.courseid = :courseid';
            $params['courseid'] = $filters['courseid'];
        }
        if (!empty($filters['userid'])) {
            $where[] = 'l.userid = :userid';
            $params['userid'] = $filters['userid'];
        }
        if (!empty($filters['action'])) {
            $where[] = 'l.action = :action';
            $params['action'] = $filters['action'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'l.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['timefrom'])) {
            $where[] = 'l.timecreated >= :timefrom';
            $params['timefrom'] = $filters['timefrom'];
        }
        if (!empty($filters['timeto'])) {
            $where[] = 'l.timecreated <= :timeto';
            $params['timeto'] = $filters['timeto'];
        }

        $wherestr = '';
        if ($where) {
            $wherestr = 'WHERE ' . implode(' AND ', $where);
        }

        $sql = "SELECT l.*, u.firstname, u.lastname, c.fullname AS coursename
                  FROM {local_areteia_log} l
             LEFT JOIN {user} u ON u.id = l.userid
             LEFT JOIN {course} c ON c.id = l.courseid
            {$wherestr}
              ORDER BY l.timecreated DESC";

        // Limit PDF export size to 500 to prevent memory exhaustion.
        $records = $DB->get_records_sql($sql, $params, 0, 500);

        $tcpdf_path = $CFG->libdir . '/tcpdf/tcpdf.php';
        if (file_exists($tcpdf_path)) {
            require_once($tcpdf_path);

            $pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);

            $pdf->SetCreator('AreteIA');
            $pdf->SetAuthor('AreteIA Reports');
            $pdf->SetTitle('Reporte de Actividad AreteIA');
            $pdf->SetSubject('Auditoria');

            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(true);
            $pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));
            $pdf->SetFooterMargin(10);

            $pdf->SetMargins(15, 15, 15);
            $pdf->SetAutoPageBreak(TRUE, 15);

            $pdf->AddPage();

            $pdf->SetFont('helvetica', 'B', 16);
            $pdf->Cell(0, 10, 'Reporte de Actividad AreteIA', 0, 1, 'C');
            $pdf->SetFont('helvetica', '', 10);
            $pdf->Cell(0, 5, 'Generado: ' . date('Y-m-d H:i:s'), 0, 1, 'C');
            $pdf->Ln(5);

            $total = count($records);
            $pdf->Cell(0, 5, 'Total registros mostrados (maximo 500): ' . $total, 0, 1, 'L');
            $pdf->Ln(5);

            $html = '
            <table border="1" cellpadding="4" cellspacing="0" style="font-size: 8pt; width: 100%;">
                <tr style="background-color: #f2f2f2; font-weight: bold; text-align: center;">
                    <th style="width: 12%;">Fecha/Hora</th>
                    <th style="width: 13%;">Usuario</th>
                    <th style="width: 15%;">Curso</th>
                    <th style="width: 18%;">Accion</th>
                    <th style="width: 5%;">Paso</th>
                    <th style="width: 17%;">Instrumento</th>
                    <th style="width: 10%;">Tokens (E/S)</th>
                    <th style="width: 5%;">Dur.</th>
                    <th style="width: 5%;">Est.</th>
                </tr>';

            foreach ($records as $row) {
                $username = $row->userid ? ($row->firstname . ' ' . $row->lastname) : 'Sistema';
                $coursename = $row->courseid ? $row->coursename : 'General';
                $actionlabel = activity_logger::get_action_label($row->action);
                $tokens = ($row->tokens_input || $row->tokens_output) ? "{$row->tokens_input}/{$row->tokens_output}" : '-';
                $duration = $row->duration_ms > 0 ? number_format($row->duration_ms / 1000, 1) . 's' : '-';

                $html .= '<tr>
                    <td>' . date('Y-m-d H:i', $row->timecreated) . '</td>
                    <td>' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</td>
                    <td>' . htmlspecialchars($coursename, ENT_QUOTES, 'UTF-8') . '</td>
                    <td>' . htmlspecialchars($actionlabel, ENT_QUOTES, 'UTF-8') . '</td>
                    <td style="text-align: center;">' . ($row->step !== null ? $row->step : '-') . '</td>
                    <td>' . htmlspecialchars($row->instrument, ENT_QUOTES, 'UTF-8') . '</td>
                    <td style="text-align: center;">' . $tokens . '</td>
                    <td style="text-align: center;">' . $duration . '</td>
                    <td style="text-align: center;">' . strtoupper($row->status) . '</td>
                </tr>';
            }

            $html .= '</table>';

            $pdf->writeHTML($html, true, false, true, false, '');

            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="reporte_areteia_' . date('Ymd_His') . '.pdf"');
            echo $pdf->Output('reporte_areteia_' . date('Ymd_His') . '.pdf', 'S');
            exit;
        } else {
            // Fallback to simple styled printable HTML.
            $filename = 'reporte_areteia_' . date('Ymd_His') . '.html';
            header('Content-Type: text/html; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');

            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Reporte de Actividad AreteIA</title>';
            echo '<style>body { font-family: sans-serif; margin: 20px; } table { width:100%; border-collapse:collapse; margin-top:20px; } th, td { border:1px solid #ccc; padding:8px; text-align:left; font-size:12px; } th { background:#f2f2f2; }</style></head><body>';
            echo '<h1>Reporte de Actividad AreteIA</h1>';
            echo '<p>Generado: ' . date('Y-m-d H:i:s') . '</p>';
            echo '<table>';
            echo '<tr><th>Fecha/Hora</th><th>Usuario</th><th>Curso</th><th>Accion</th><th>Paso</th><th>Instrumento</th><th>Tokens (E/S)</th><th>Duracion</th><th>Estado</th></tr>';

            foreach ($records as $row) {
                $username = $row->userid ? ($row->firstname . ' ' . $row->lastname) : 'Sistema';
                $coursename = $row->courseid ? $row->coursename : 'General';
                $actionlabel = activity_logger::get_action_label($row->action);
                $tokens = ($row->tokens_input || $row->tokens_output) ? "{$row->tokens_input}/{$row->tokens_output}" : '-';
                $duration = $row->duration_ms > 0 ? number_format($row->duration_ms / 1000, 1) . 's' : '-';

                echo '<tr>';
                echo '<td>' . date('Y-m-d H:i', $row->timecreated) . '</td>';
                echo '<td>' . htmlspecialchars($username) . '</td>';
                echo '<td>' . htmlspecialchars($coursename) . '</td>';
                echo '<td>' . htmlspecialchars($actionlabel) . '</td>';
                echo '<td style="text-align:center;">' . ($row->step !== null ? $row->step : '-') . '</td>';
                echo '<td>' . htmlspecialchars($row->instrument) . '</td>';
                echo '<td style="text-align:center;">' . $tokens . '</td>';
                echo '<td style="text-align:center;">' . $duration . '</td>';
                echo '<td style="text-align:center;">' . strtoupper($row->status) . '</td>';
                echo '</tr>';
            }
            echo '</table></body></html>';
            exit;
        }
    }
}
