<?php
/**
 * Capability definitions for local_areteia.
 *
 * @package    local_areteia
 * @copyright  2026 Vicente Astorga
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    /*
     * Allows a user to access and use the AreteIA pedagogical workflow
     * within a course. Assign to editingteacher so regular teachers can
     * use the tool without needing site-admin or manager rights.
     */
    'local/areteia:use' => [
        'riskbitmask'  => RISK_SPAM,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'manager'        => CAP_ALLOW,
            'coursecreator'  => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ],
    ],

    /*
     * Allows a user to view the activity reports of AreteIA.
     */
    'local/areteia:viewreports' => [
        'riskbitmask'  => RISK_PERSONAL, // May see other users' actions
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

];
