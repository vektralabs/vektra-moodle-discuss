<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Injection of the student container on the results pages.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\local;

/**
 * Emits the placeholder and the AMD call on the target pages; never calls Vektra.
 *
 * The page must never wait on Vektra latency or an outage: the state is fetched by the AMD module
 * through ajax.php after the page has loaded.
 */
final class injector {
    /** @var string[] Target page type => module name. */
    public const TARGETS = [
        'mod-quiz-review' => 'quiz',
        'mod-quiz-view' => 'quiz',
        'mod-assign-view' => 'assign',
    ];

    /**
     * Shared entry point of the legacy callback (4.3) and the hook callback (4.4+).
     *
     * @param \moodle_page|null $page The page (default: $PAGE).
     * @param config|null $config
     * @return string HTML, empty when nothing applies.
     */
    public static function inject(?\moodle_page $page = null, ?config $config = null): string {
        global $PAGE;
        $page = $page ?? $PAGE;
        $config = $config ?? new config();

        $modname = self::TARGETS[$page->pagetype] ?? null;
        if ($modname === null || !$config->is_enabled()) {
            return '';
        }
        $cm = $page->cm;
        if (!$cm || $cm->modname !== $modname) {
            return '';
        }
        $context = \context_module::instance($cm->id);
        if (!has_any_capability(['local/vektradiscuss:start', 'local/vektradiscuss:viewowntranscript'], $context)) {
            return '';
        }
        $uiorigin = self::usable_ui_origin($config);
        if ($uiorigin === '') {
            if (has_capability('moodle/site:config', \context_system::instance())) {
                debugging('local_vektradiscuss: the discussion UI origin is not set, not https, or equal to the Moodle '
                    . 'origin; the discussion is not shown.', DEBUG_NORMAL);
            }
            return '';
        }

        $attemptid = $page->pagetype === 'mod-quiz-review' ? optional_param('attempt', 0, PARAM_INT) : 0;
        $page->requires->js_call_amd('local_vektradiscuss/entry', 'init', [[
            'cmid' => (int) $cm->id,
            'attemptid' => (int) $attemptid,
            // Not a secret: the iframe src and the only origin accepted by the handshake.
            'uiorigin' => $uiorigin,
        ]]);
        return \html_writer::div('', 'local-vektradiscuss', [
            'data-region' => 'local_vektradiscuss',
            'data-cmid' => (int) $cm->id,
        ]);
    }

    /**
     * The discussion UI origin, only when it is usable: https (config enforces it) and distinct from
     * the Moodle origin, because the iframe sandbox needs allow-same-origin (ARCH-076).
     *
     * @param config $config
     * @return string The origin, or empty.
     */
    public static function usable_ui_origin(config $config): string {
        global $CFG;
        $origin = $config->ui_origin();
        if ($origin === '' || $origin === config::origin_of($CFG->wwwroot)) {
            return '';
        }
        return $origin;
    }
}
