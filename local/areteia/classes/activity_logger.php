<?php
/**
 * Central activity logging service for AreteIA.
 *
 * All plugin actions (sync, ingest, generate, inject, export, etc.) are
 * recorded here for auditing and configurable reporting.
 *
 * @package    local_areteia
 * @copyright  2026 Vicente Astorga
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_areteia;

defined('MOODLE_INTERNAL') || die();

class activity_logger {

    /**
     * Human-readable labels for each action (used in reports).
     */
    public const ACTION_LABELS = [
        'page_view'       => 'Vista de página',
        'sync'            => 'Sincronizar archivos',
        'ingest'          => 'Generar biblioteca (embeddings)',
        'delete_rag'      => 'Eliminar biblioteca',
        'search'          => 'Búsqueda semántica',
        'generate_step4'  => 'Generar sugerencias de instrumento',
        'generate_step5'  => 'Diseñar ítems de evaluación',
        'generate_step5_1'=> 'Ajustar ítem individual',
        'generate_step6'  => 'Generar rúbrica',
        'generate_step9'  => 'Generar instrumento de corrección',
        'inject_quiz'     => 'Inyectar cuestionario en Moodle',
        'inject_assign'   => 'Inyectar tarea en Moodle',
        'inject_forum'    => 'Inyectar foro en Moodle',
        'export_pdf'      => 'Exportar a PDF',
        'export_docx'     => 'Exportar a DOCX',
        'save_item'       => 'Guardar edición de ítem',
        'preview'         => 'Previsualizar prompt de IA',
        'adjust_item'     => 'Ajustar ítem con IA',
    ];

    /**
     * Log an action to the database.
     *
     * @param int    $courseid  The course ID
     * @param string $action    Action identifier (see ACTION_LABELS keys)
     * @param array  $extra     Optional metadata overrides:
     *   'step'            => int     Pipeline step number
     *   'instrument'      => string  Selected instrument name
     *   'instrument_type' => string  'assign', 'quiz', 'forum'
     *   'detail'          => mixed   Arbitrary data (will be JSON-encoded if array/object)
     *   'tokens_input'    => int     LLM input tokens
     *   'tokens_output'   => int     LLM output tokens
     *   'duration_ms'     => int     Action duration in milliseconds
     *   'status'          => string  'ok', 'error', 'timeout'
     */
    public static function log(int $courseid, string $action, array $extra = []): void {
        global $DB, $USER;

        try {
            $record = new \stdClass();
            $record->userid          = $USER->id ?? 0;
            $record->courseid        = $courseid;
            $record->action          = $action;
            $record->step            = $extra['step'] ?? null;
            $record->instrument      = $extra['instrument'] ?? '';
            $record->instrument_type = $extra['instrument_type'] ?? '';
            $record->tokens_input    = (int)($extra['tokens_input'] ?? 0);
            $record->tokens_output   = (int)($extra['tokens_output'] ?? 0);
            $record->duration_ms     = (int)($extra['duration_ms'] ?? 0);
            $record->status          = $extra['status'] ?? 'ok';
            $record->timecreated     = time();

            // Encode detail to JSON if it's an array or object.
            $detail = $extra['detail'] ?? '';
            if (is_array($detail) || is_object($detail)) {
                $detail = json_encode($detail, JSON_UNESCAPED_UNICODE);
            }
            $record->detail = (string)$detail;

            $DB->insert_record('local_areteia_log', $record, false);
        } catch (\Throwable $e) {
            // Never let logging failures break the main workflow.
            error_log('[AreteIA] activity_logger::log failed: ' . $e->getMessage());
        }
    }

    /**
     * Wrap a callable with timing and log the result.
     *
     * @param int      $courseid
     * @param string   $action
     * @param callable $fn       The function to execute and time
     * @param array    $extra    Additional metadata
     * @return mixed   The return value of $fn
     */
    public static function timed(int $courseid, string $action, callable $fn, array $extra = []) {
        $start = microtime(true);
        $status = 'ok';
        $result = null;

        try {
            $result = $fn();
        } catch (\Throwable $e) {
            $status = 'error';
            $extra['detail'] = array_merge(
                (array)($extra['detail'] ?? []),
                ['error' => $e->getMessage()]
            );
            throw $e; // Re-throw so the caller handles the error
        } finally {
            $elapsed = (int)((microtime(true) - $start) * 1000);
            $extra['duration_ms'] = $elapsed;
            $extra['status'] = $status;
            self::log($courseid, $action, $extra);
        }

        return $result;
    }

    /**
     * Get the human-readable label for an action.
     *
     * @param string $action
     * @return string
     */
    public static function get_action_label(string $action): string {
        return self::ACTION_LABELS[$action] ?? $action;
    }

    /**
     * Get all known action identifiers.
     *
     * @return array
     */
    public static function get_all_actions(): array {
        return array_keys(self::ACTION_LABELS);
    }
}
