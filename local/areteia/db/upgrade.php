<?php
/**
 * Upgrade steps for local_areteia.
 *
 * @package    local_areteia
 * @copyright  2026 Vicente Astorga
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_local_areteia_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026060301) {
        // Define table local_areteia_log.
        $table = new xmldb_table('local_areteia_log');

        $table->add_field('id',              XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('userid',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('courseid',        XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('action',          XMLDB_TYPE_CHAR,    '50', null, XMLDB_NOTNULL);
        $table->add_field('step',            XMLDB_TYPE_INTEGER, '3',  null, null);
        $table->add_field('instrument',      XMLDB_TYPE_CHAR,    '255', null, null, null, '');
        $table->add_field('instrument_type', XMLDB_TYPE_CHAR,    '50', null, null, null, '');
        $table->add_field('detail',          XMLDB_TYPE_TEXT,    null, null, null);
        $table->add_field('tokens_input',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('tokens_output',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('duration_ms',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status',          XMLDB_TYPE_CHAR,    '20', null, XMLDB_NOTNULL, null, 'ok');
        $table->add_field('timecreated',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        $table->add_index('idx_course_time', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'timecreated']);
        $table->add_index('idx_user_time',   XMLDB_INDEX_NOTUNIQUE, ['userid', 'timecreated']);
        $table->add_index('idx_action_time', XMLDB_INDEX_NOTUNIQUE, ['action', 'timecreated']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026060301, 'local', 'areteia');
    }

    return true;
}
