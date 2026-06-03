<?php
/**
 * Table SQL class for displaying AreteIA activity logs.
 *
 * @package    local_areteia
 * @copyright  2026 Vicente Astorga
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_areteia;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/tablelib.php');

class report_table extends \table_sql {

    /**
     * Constructor.
     *
     * @param string $uniqueid Unique ID of the table.
     */
    public function __construct($uniqueid) {
        parent::__construct($uniqueid);

        $columns = [
            'timecreated',
            'fullname',
            'course',
            'action',
            'step',
            'instrument',
            'tokens',
            'duration_ms',
            'status',
            'detail'
        ];

        $headers = [
            'Fecha/Hora',
            'Usuario',
            'Curso',
            'Acción',
            'Paso',
            'Instrumento',
            'Tokens (E/S)',
            'Duración',
            'Estado',
            'Detalles'
        ];

        $this->define_columns($columns);
        $this->define_headers($headers);

        $this->sortable(true, 'timecreated', SORT_DESC);
        $this->no_sorting('detail');
        $this->no_sorting('tokens');

        // Set page size.
        $this->pagesize(20, 100);
    }

    /**
     * Setup the query with filter conditions.
     *
     * @param array $filters Filter parameters.
     */
    public function setup_query(array $filters = []) {
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
            $wherestr = implode(' AND ', $where);
        }

        $fields = 'l.id, l.userid, l.courseid, l.action, l.step, l.instrument, l.instrument_type, l.tokens_input, l.tokens_output, l.duration_ms, l.status, l.timecreated, l.detail,
                   u.firstname, u.lastname, c.fullname AS coursename';
        $from = '{local_areteia_log} l
                 LEFT JOIN {user} u ON u.id = l.userid
                 LEFT JOIN {course} c ON c.id = l.courseid';

        $this->set_sql($fields, $from, $wherestr, $params);
    }

    /**
     * Format timecreated column.
     */
    public function col_timecreated($row) {
        return userdate($row->timecreated, get_string('strftimedatetime', 'langconfig'));
    }

    /**
     * Format user fullname column.
     */
    public function col_fullname($row) {
        if (!$row->userid) {
            return 'Sistema';
        }
        $url = new \moodle_url('/user/view.php', ['id' => $row->userid]);
        $fullname = trim($row->firstname . ' ' . $row->lastname);
        if (empty($fullname)) {
            $fullname = 'ID: ' . $row->userid;
        }
        return \html_writer::link($url, htmlspecialchars($fullname, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Format course column.
     */
    public function col_course($row) {
        if (!$row->courseid) {
            return 'General';
        }
        $url = new \moodle_url('/course/view.php', ['id' => $row->courseid]);
        return \html_writer::link($url, format_string($row->coursename));
    }

    /**
     * Format action column.
     */
    public function col_action($row) {
        $label = activity_logger::get_action_label($row->action);
        return \html_writer::span(
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
            'badge badge-secondary p-2 action-badge action-' . $row->action
        );
    }

    /**
     * Format step column.
     */
    public function col_step($row) {
        return ($row->step !== null) ? $row->step : '-';
    }

    /**
     * Format instrument column.
     */
    public function col_instrument($row) {
        $display = htmlspecialchars($row->instrument, ENT_QUOTES, 'UTF-8');
        if (!empty($row->instrument_type)) {
            $display .= ' <small class="text-muted">(' . strtoupper($row->instrument_type) . ')</small>';
        }
        return $display ?: '-';
    }

    /**
     * Format tokens column.
     */
    public function col_tokens($row) {
        if ($row->tokens_input || $row->tokens_output) {
            return '<span class="badge badge-info bg-info text-white">' . $row->tokens_input . '</span> / ' .
                   '<span class="badge badge-primary bg-primary text-white">' . $row->tokens_output . '</span>';
        }
        return '-';
    }

    /**
     * Format duration column.
     */
    public function col_duration_ms($row) {
        if ($row->duration_ms > 0) {
            return number_format($row->duration_ms / 1000, 2) . ' s';
        }
        return '-';
    }

    /**
     * Format status column.
     */
    public function col_status($row) {
        $status = strtolower($row->status);
        $class = 'badge ';
        if ($status === 'ok') {
            $class .= 'badge-success bg-success text-white';
        } else if ($status === 'error') {
            $class .= 'badge-danger bg-danger text-white';
        } else {
            $class .= 'badge-warning bg-warning text-dark';
        }
        return \html_writer::span(strtoupper($status), $class . ' p-2');
    }

    /**
     * Format detail column.
     */
    public function col_detail($row) {
        if (empty($row->detail) || $row->detail === '""' || $row->detail === '[]' || $row->detail === '{}') {
            return '-';
        }

        $detailid = 'detail-modal-' . $row->id;
        $escaped = htmlspecialchars($row->detail, ENT_QUOTES, 'UTF-8');

        // Formatted button to trigger modal
        $btn = \html_writer::tag('button', 'Ver detalle', [
            'type' => 'button',
            'class' => 'btn btn-xs btn-outline-secondary p-1 font-weight-bold',
            'data-toggle' => 'modal',
            'data-target' => '#' . $detailid
        ]);

        // Simple Bootstrap modal markup compatible with Moodle themes
        $modal = '
        <div class="modal fade" id="' . $detailid . '" tabindex="-1" role="dialog" aria-labelledby="' . $detailid . '-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header bg-light">
                        <h5 class="modal-title" id="' . $detailid . '-title">Detalles del Evento #' . $row->id . '</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-2"><strong>Acción:</strong> ' . htmlspecialchars(activity_logger::get_action_label($row->action), ENT_QUOTES, 'UTF-8') . '</div>
                        <div><strong>Datos del Evento:</strong></div>
                        <pre class="bg-dark text-light p-3 rounded mt-2" style="white-space: pre-wrap; word-break: break-all; max-height: 400px; overflow-y: auto;">' . $escaped . '</pre>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>';

        return $btn . $modal;
    }
}
