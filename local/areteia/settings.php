<?php
/**
 * Settings configuration for local_areteia.
 * Adds the report link to the Site Administration under Reports.
 *
 * @package    local_areteia
 * @copyright  2026 Vicente Astorga
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig || has_capability('local/areteia:viewreports', context_system::instance())) {
    $ADMIN->add('reports', new admin_externalpage(
        'local_areteia_reports',
        get_string('report_title', 'local_areteia'),
        new moodle_url('/local/areteia/report.php'),
        'local/areteia:viewreports'
    ));
}
