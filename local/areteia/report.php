<?php
/**
 * Main activity report page for AreteIA plugin.
 *
 * @package    local_areteia
 * @copyright  2026 Vicente Astorga
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Parameters.
$courseid     = optional_param('courseid', 0, PARAM_INT);
$userid       = optional_param('userid', 0, PARAM_INT);
$actionfilter = optional_param('actionfilter', '', PARAM_TEXT);
$statusfilter = optional_param('statusfilter', '', PARAM_TEXT);
$timefrom     = optional_param('timefrom', '', PARAM_TEXT);
$timeto       = optional_param('timeto', '', PARAM_TEXT);
$export       = optional_param('export', '', PARAM_ALPHA);

// Capability and login checks.
$systemcontext = \context_system::instance();
require_capability('local/areteia:viewreports', $systemcontext);

if ($courseid) {
    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    require_login($course);
    $PAGE->set_url(new \moodle_url('/local/areteia/report.php', ['courseid' => $courseid]));
    $PAGE->set_title(get_string('pluginname', 'local_areteia') . ': Reportes');
    $PAGE->set_heading($course->fullname);
} else {
    require_login();
    $PAGE->set_url(new \moodle_url('/local/areteia/report.php'));
    $PAGE->set_title(get_string('pluginname', 'local_areteia') . ': Reportes');
    $PAGE->set_heading(get_string('pluginname', 'local_areteia') . ': Reportes de Auditoría');
}

// Convert dates to timestamps.
$timefrom_ts = !empty($timefrom) ? strtotime($timefrom . ' 00:00:00') : 0;
$timeto_ts   = !empty($timeto) ? strtotime($timeto . ' 23:59:59') : 0;

$filters = [
    'courseid' => $courseid,
    'userid'   => $userid,
    'action'   => $actionfilter,
    'status'   => $statusfilter,
    'timefrom' => $timefrom_ts,
    'timeto'   => $timeto_ts
];

// Handle Exports before page layout is initialized.
if ($export === 'csv') {
    \local_areteia\report_export::csv($filters);
} else if ($export === 'pdf') {
    \local_areteia\report_export::pdf($filters);
}

// Query logged courses and users to populate filters.
$courses_sql = "SELECT DISTINCT c.id, c.fullname
                  FROM {local_areteia_log} l
                  JOIN {course} c ON c.id = l.courseid
              ORDER BY c.fullname";
$logged_courses = $DB->get_records_sql($courses_sql);

$users_sql = "SELECT DISTINCT u.id, u.firstname, u.lastname
                FROM {local_areteia_log} l
                JOIN {user} u ON u.id = l.userid
            ORDER BY u.lastname, u.firstname";
$logged_users = $DB->get_records_sql($users_sql);

// Compute live dashboard metrics based on filters.
$where = [];
$params = [];
if (!empty($courseid)) {
    $where[] = 'courseid = :courseid';
    $params['courseid'] = $courseid;
}
if (!empty($userid)) {
    $where[] = 'userid = :userid';
    $params['userid'] = $userid;
}
if (!empty($actionfilter)) {
    $where[] = 'action = :action';
    $params['action'] = $actionfilter;
}
if (!empty($statusfilter)) {
    $where[] = 'status = :status';
    $params['status'] = $statusfilter;
}
if ($timefrom_ts) {
    $where[] = 'timecreated >= :timefrom';
    $params['timefrom'] = $timefrom_ts;
}
if ($timeto_ts) {
    $where[] = 'timecreated <= :timeto';
    $params['timeto'] = $timeto_ts;
}

$wherestr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$totalactions = $DB->count_records_sql("SELECT COUNT(*) FROM {local_areteia_log} {$wherestr}", $params);

$tokenstats = $DB->get_record_sql("SELECT SUM(tokens_input) as input, SUM(tokens_output) as output FROM {local_areteia_log} {$wherestr}", $params);
$totaltokens = ($tokenstats->input ?? 0) + ($tokenstats->output ?? 0);

$okcount = $DB->count_records_sql("SELECT COUNT(*) FROM {local_areteia_log} " . ($wherestr ? $wherestr . " AND status = 'ok'" : "WHERE status = 'ok'"), $params);
$successrate = $totalactions > 0 ? round(($okcount / $totalactions) * 100, 1) : 100;

$durstats = $DB->get_record_sql("SELECT AVG(duration_ms) as avgdur FROM {local_areteia_log} " . ($wherestr ? $wherestr . " AND duration_ms > 0" : "WHERE duration_ms > 0"), $params);
$avgduration = $durstats->avgdur ? round($durstats->avgdur / 1000, 2) : 0;

// Set up page layout.
$PAGE->set_context($courseid ? \context_course::instance($courseid) : $systemcontext);
$PAGE->set_pagelayout('admin');

// Page Output.
echo $OUTPUT->header();

// Rich premium CSS styling.
echo '
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    .areteia-dashboard {
        font-family: "Outfit", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        color: #2d3748;
    }
    .areteia-title-section {
        display: flex;
        align-items: center;
        margin-bottom: 25px;
    }
    .areteia-title-icon {
        background: linear-gradient(135deg, #6c63ff 0%, #3f3d56 100%);
        color: #fff;
        width: 50px;
        height: 50px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        margin-right: 15px;
        box-shadow: 0 4px 15px rgba(108, 99, 255, 0.2);
    }
    .areteia-title-section h2 {
        margin: 0;
        font-weight: 700;
        font-size: 26px;
        color: #1a202c;
    }
    .areteia-title-section p {
        margin: 0;
        color: #718096;
        font-size: 14px;
    }
    
    /* Metrics Grid */
    .areteia-metrics-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    .metric-card {
        border-radius: 16px;
        color: #fff;
        padding: 24px;
        box-shadow: 0 10px 20px rgba(0, 0, 0, 0.05);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        position: relative;
        overflow: hidden;
    }
    .metric-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 30px rgba(0, 0, 0, 0.12);
    }
    .metric-card::before {
        content: "";
        position: absolute;
        width: 150px;
        height: 150px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 50%;
        top: -50px;
        right: -50px;
    }
    .metric-card-1 {
        background: linear-gradient(135deg, #5b3cc4 0%, #3b82f6 100%);
    }
    .metric-card-2 {
        background: linear-gradient(135deg, #0d9488 0%, #10b981 100%);
    }
    .metric-card-3 {
        background: linear-gradient(135deg, #ea580c 0%, #f43f5e 100%);
    }
    .metric-card-4 {
        background: linear-gradient(135deg, #1e293b 0%, #475569 100%);
    }
    .metric-card-title {
        font-size: 14px;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        opacity: 0.9;
        margin-bottom: 8px;
    }
    .metric-card-value {
        font-size: 32px;
        font-weight: 700;
        margin-bottom: 4px;
    }
    .metric-card-sub {
        font-size: 12px;
        opacity: 0.8;
    }

    /* Filters Box */
    .areteia-filters-card {
        background: rgba(255, 255, 255, 0.85);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(226, 232, 240, 0.8);
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 30px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.02);
    }
    .areteia-filters-title {
        font-weight: 600;
        font-size: 16px;
        margin-bottom: 20px;
        color: #2d3748;
        display: flex;
        align-items: center;
    }
    .areteia-filters-title i {
        margin-right: 8px;
    }
    .areteia-filters-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }
    .filter-group {
        display: flex;
        flex-direction: column;
    }
    .filter-group label {
        font-size: 12px;
        font-weight: 600;
        color: #718096;
        margin-bottom: 6px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .filter-control {
        border: 1px solid #cbd5e0;
        border-radius: 8px;
        padding: 8px 12px;
        font-size: 14px;
        color: #4a5568;
        background-color: #fff;
        transition: border-color 0.2s;
    }
    .filter-control:focus {
        border-color: #6c63ff;
        outline: none;
    }
    .areteia-filter-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-top: 1px solid #e2e8f0;
        padding-top: 15px;
        margin-top: 15px;
    }
    .btn-areteia-primary {
        background-color: #6c63ff;
        color: #fff !important;
        border: none;
        border-radius: 8px;
        padding: 10px 20px;
        font-weight: 500;
        transition: background-color 0.2s, transform 0.1s;
    }
    .btn-areteia-primary:hover {
        background-color: #574feb;
        transform: translateY(-1px);
    }
    .btn-areteia-outline {
        border: 1px solid #e2e8f0;
        color: #4a5568 !important;
        background-color: #fff;
        border-radius: 8px;
        padding: 10px 20px;
        font-weight: 500;
        transition: background-color 0.2s;
    }
    .btn-areteia-outline:hover {
        background-color: #f7fafc;
    }
    .btn-export {
        margin-left: 10px;
    }

    /* Badges in Table */
    .action-badge {
        font-size: 11px;
        font-weight: 500;
        border-radius: 6px;
    }
    .btn-xs {
        font-size: 11px;
        padding: 2px 6px;
        border-radius: 4px;
    }
</style>
<div class="areteia-dashboard">

    <!-- Header Section -->
    <div class="areteia-title-section">
        <div class="areteia-title-icon">📊</div>
        <div>
            <h2>Dashboard de Auditoría AreteIA</h2>
            <p>Monitoreo en tiempo real de interacciones con IA, consumo de tokens y exportaciones.</p>
        </div>
    </div>

    <!-- Metrics Cards -->
    <div class="areteia-metrics-grid">
        <div class="metric-card metric-card-1">
            <div class="metric-card-title">Total Actividades</div>
            <div class="metric-card-value">' . number_format($totalactions) . '</div>
            <div class="metric-card-sub">Eventos registrados con filtros</div>
        </div>
        <div class="metric-card metric-card-2">
            <div class="metric-card-title">Consumo de Tokens</div>
            <div class="metric-card-value">' . number_format($totaltokens) . '</div>
            <div class="metric-card-sub">Entrada: ' . number_format($tokenstats->input ?? 0) . ' | Salida: ' . number_format($tokenstats->output ?? 0) . '</div>
        </div>
        <div class="metric-card metric-card-3">
            <div class="metric-card-title">Tasa de Éxito</div>
            <div class="metric-card-value">' . $successrate . '%</div>
            <div class="metric-card-sub">Acciones finalizadas correctamente</div>
        </div>
        <div class="metric-card metric-card-4">
            <div class="metric-card-title">Tiempo Promedio</div>
            <div class="metric-card-value">' . $avgduration . 's</div>
            <div class="metric-card-sub">Duración promedio de generación IA</div>
        </div>
    </div>

    <!-- Filters Panel -->
    <div class="areteia-filters-card">
        <div class="areteia-filters-title">
            <span>⚙️ Filtrar Reporte</span>
        </div>
        <form method="get" action="' . $PAGE->url->out_omit_querystring() . '">
            ';
            if ($courseid) {
                echo '<input type="hidden" name="courseid" value="' . $courseid . '">';
            }
            echo '
            <div class="areteia-filters-grid">
                ';
                if (!$courseid) {
                    echo '
                    <div class="filter-group">
                        <label for="course-filter">Curso</label>
                        <select name="courseid" id="course-filter" class="filter-control">
                            <option value="0">Todos los cursos</option>
                            ';
                            foreach ($logged_courses as $c) {
                                $selected = ($courseid == $c->id) ? 'selected' : '';
                                echo '<option value="' . $c->id . '" ' . $selected . '>' . htmlspecialchars($c->fullname, ENT_QUOTES, 'UTF-8') . '</option>';
                            }
                            echo '
                        </select>
                    </div>';
                }
                echo '
                <div class="filter-group">
                    <label for="user-filter">Usuario</label>
                    <select name="userid" id="user-filter" class="filter-control">
                        <option value="0">Todos los usuarios</option>
                        ';
                        foreach ($logged_users as $u) {
                            $selected = ($userid == $u->id) ? 'selected' : '';
                            $name = trim($u->firstname . ' ' . $u->lastname);
                            echo '<option value="' . $u->id . '" ' . $selected . '>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</option>';
                        }
                        echo '
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="action-filter">Acción</label>
                    <select name="actionfilter" id="action-filter" class="filter-control">
                        <option value="">Todas las acciones</option>
                        ';
                        foreach (\local_areteia\activity_logger::ACTION_LABELS as $key => $lbl) {
                            $selected = ($actionfilter === $key) ? 'selected' : '';
                            echo '<option value="' . $key . '" ' . $selected . '>' . htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') . '</option>';
                        }
                        echo '
                    </select>
                </div>

                <div class="filter-group">
                    <label for="status-filter">Estado</label>
                    <select name="statusfilter" id="status-filter" class="filter-control">
                        <option value="">Todos</option>
                        <option value="ok" ' . ($statusfilter === 'ok' ? 'selected' : '') . '>OK</option>
                        <option value="error" ' . ($statusfilter === 'error' ? 'selected' : '') . '>ERROR</option>
                        <option value="timeout" ' . ($statusfilter === 'timeout' ? 'selected' : '') . '>TIMEOUT</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="timefrom-filter">Desde</label>
                    <input type="date" name="timefrom" id="timefrom-filter" class="filter-control" value="' . htmlspecialchars($timefrom, ENT_QUOTES, 'UTF-8') . '">
                </div>

                <div class="filter-group">
                    <label for="timeto-filter">Hasta</label>
                    <input type="date" name="timeto" id="timeto-filter" class="filter-control" value="' . htmlspecialchars($timeto, ENT_QUOTES, 'UTF-8') . '">
                </div>
            </div>
            
            <div class="areteia-filter-actions">
                <div>
                    <button type="submit" class="btn-areteia-primary">Aplicar Filtros</button>
                    <a href="' . $PAGE->url->out_omit_querystring() . ($courseid ? '?courseid=' . $courseid : '') . '" class="btn-areteia-outline ml-2">Restablecer</a>
                </div>
                <div>
                    <span class="text-muted font-weight-bold mr-2">Exportar:</span>
                    <button type="submit" name="export" value="csv" class="btn-areteia-outline btn-export">CSV</button>
                    <button type="submit" name="export" value="pdf" class="btn-areteia-outline btn-export">PDF</button>
                </div>
            </div>
        </form>
    </div>
';

// Render logs table.
$table = new \local_areteia\report_table('local_areteia_report_table');
$table->setup_query($filters);
$table->define_baseurl($PAGE->url);

echo '<div class="bg-white p-3 rounded border shadow-sm">';
$table->out(20, true);
echo '</div>';

echo '</div>'; // areteia-dashboard

echo $OUTPUT->footer();
