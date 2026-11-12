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
 * Privacy API provider.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_vektradiscuss\local\privacy_api;

/**
 * The plugin stores nothing in Moodle: discussions and transcripts live in Vektra (an external location).
 *
 * Export reads a person's transcripts from Vektra and writes the exact bytes, after checking each one against
 * the hash Vektra reports. Deletion asks Vektra to erase, per activity when the request is scoped to
 * activities, then reads back to verify that nothing is left. Any failure, a missing key, a verification that
 * finds data, or Vektra keeping the data for exam evidence throws: the request is never marked as done
 * without the deletion (OQ-033).
 *
 * Transcripts of activities that no longer exist in Moodle are placed in the person's user context.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var privacy_api|null Replaced in tests. */
    public static ?privacy_api $api = null;

    /**
     * Describe the data held in Vektra.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link('vektra_assess', [
            'userid' => 'privacy:metadata:vektra_assess:userid',
            'courseid' => 'privacy:metadata:vektra_assess:courseid',
            'cmid' => 'privacy:metadata:vektra_assess:cmid',
            'attempt' => 'privacy:metadata:vektra_assess:attempt',
            'submission' => 'privacy:metadata:vektra_assess:submission',
            'conversation' => 'privacy:metadata:vektra_assess:conversation',
            'transcript' => 'privacy:metadata:vektra_assess:transcript',
        ], 'privacy:metadata:vektra_assess');
        return $collection;
    }

    /**
     * The contexts with transcripts of a person: the activities, or the person's own context for activities
     * deleted from Moodle.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $api = self::api();
        if (!$api->in_use()) {
            return $contextlist;
        }
        $ids = [];
        foreach ($api->subject_transcripts($userid) as $item) {
            $ids[] = self::context_of((int) $item['cmid'], $userid)->id;
        }
        if ($ids) {
            global $DB;
            [$sql, $params] = $DB->get_in_or_equal(array_values(array_unique($ids)), SQL_PARAMS_NAMED);
            $contextlist->add_from_sql("SELECT id FROM {context} WHERE id $sql", $params);
        }
        return $contextlist;
    }

    /**
     * The people with data in an activity.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        $api = self::api();
        if (!$context instanceof \context_module || !self::is_discussion_module($context) || !$api->in_use()) {
            return;
        }
        $userlist->add_users($api->activity_subjects((int) $context->instanceid));
    }

    /**
     * Export a person's transcripts, the exact bytes, per context.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        if (!$contextlist->count()) {
            return;
        }
        $userid = (int) $contextlist->get_user()->id;
        $items = self::api()->subject_transcripts($userid);
        foreach ($contextlist->get_contexts() as $context) {
            foreach ($items as $item) {
                if (self::context_of((int) $item['cmid'], $userid)->id !== $context->id) {
                    continue;
                }
                $subcontext = [
                    get_string('privacy:path', 'local_vektradiscuss'),
                    get_string('privacy:session', 'local_vektradiscuss', (object) [
                        'cmid' => $item['cmid'],
                        'n' => $item['session_no'],
                    ]),
                ];
                writer::with_context($context)
                    ->export_data($subcontext, (object) [
                        'cmid' => $item['cmid'],
                        'courseid' => $item['course_id'],
                        'session' => $item['session_no'],
                        'closed' => $item['closed_at'],
                        'sha256' => $item['sha256'],
                    ])
                    ->export_custom_file($subcontext, $item['filename'], $item['bytes']);
            }
        }
    }

    /**
     * Erase an activity's data for everyone, then verify.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        $api = self::api();
        if (!$context instanceof \context_module || !self::is_discussion_module($context) || !$api->in_use()) {
            return;
        }
        $cmid = (int) $context->instanceid;
        $api->erase_activity($cmid, null);
        if ($api->activity_subjects($cmid)) {
            throw new \moodle_exception('privacy_erase_unverified', 'local_vektradiscuss');
        }
    }

    /**
     * Erase a person's data in the approved contexts, then verify.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $api = self::api();
        if (!$contextlist->count() || !$api->in_use()) {
            return;
        }
        $userid = (int) $contextlist->get_user()->id;
        $cmids = [];
        $subjectwide = false;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_module && self::is_discussion_module($context)) {
                $cmids[] = (int) $context->instanceid;
            } else if ($context instanceof \context_user && (int) $context->instanceid === $userid) {
                // Activities deleted from Moodle have no module context left: erase the person entirely.
                $subjectwide = true;
            }
        }
        if ($subjectwide) {
            $api->erase_subject($userid);
        } else {
            foreach ($cmids as $cmid) {
                $api->erase_activity($cmid, [$userid]);
            }
        }
        foreach ($api->subject_transcripts($userid) as $item) {
            if ($subjectwide || in_array((int) $item['cmid'], $cmids, true)) {
                throw new \moodle_exception('privacy_erase_unverified', 'local_vektradiscuss');
            }
        }
    }

    /**
     * Erase the data of some people in an activity, then verify.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        $api = self::api();
        $userids = array_map('intval', $userlist->get_userids());
        if (!$userids || !$context instanceof \context_module || !self::is_discussion_module($context) || !$api->in_use()) {
            return;
        }
        $cmid = (int) $context->instanceid;
        $api->erase_activity($cmid, $userids);
        if (array_intersect($userids, $api->activity_subjects($cmid))) {
            throw new \moodle_exception('privacy_erase_unverified', 'local_vektradiscuss');
        }
    }

    /**
     * The API (replaceable in tests).
     *
     * @return privacy_api
     */
    private static function api(): privacy_api {
        return self::$api ?? new privacy_api();
    }

    /**
     * The context a transcript belongs to: its activity, or the person's context when the activity is gone.
     *
     * @param int $cmid
     * @param int $userid
     * @return \context
     */
    private static function context_of(int $cmid, int $userid): \context {
        $context = $cmid > 0 ? \context_module::instance($cmid, IGNORE_MISSING) : false;
        return $context ?: \context_user::instance($userid);
    }

    /**
     * Whether a module context is a quiz or an assignment.
     *
     * @param \context_module $context
     * @return bool
     */
    private static function is_discussion_module(\context_module $context): bool {
        global $DB;
        $modname = $DB->get_field_sql(
            'SELECT m.name FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module WHERE cm.id = ?',
            [$context->instanceid]
        );
        return in_array($modname, ['quiz', 'assign'], true);
    }
}
