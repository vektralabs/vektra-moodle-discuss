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
 * Vektra lists every activity where it holds a row of the person that an erasure would remove (vektra-stack
 * #215). Export writes, per activity, the sessions with their turns (or why they are missing), the new
 * sessions granted, the extra time, the retention tombstones and the technical record counts, and each
 * transcript's exact bytes, checked against the hash Vektra reports. A damaged transcript, or content that
 * cannot be exported, is named in an exception after everything else is written. Deletion asks Vektra to
 * erase activity by activity (see erase_person()), then reads the list back to verify that nothing is left.
 *
 * Failures throw a moodle_exception with an explicit message (a missing key, Vektra failing or still
 * erasing, data still found, data kept as exam evidence). Moodle's privacy manager catches it
 * (core_privacy\manager::handled_component_class_callback()), sends the message to the site's Data
 * Protection Officers and completes the request anyway: a plugin cannot mark it as failed. The plugin keeps
 * no record of its own (COMP-001); Vektra alerts its operator when an erasure fails or stays pending.
 *
 * The person's user context is always listed: it holds the data of activities deleted from Moodle.
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
            'grants' => 'privacy:metadata:vektra_assess:grants',
            'technical' => 'privacy:metadata:vektra_assess:technical',
        ], 'privacy:metadata:vektra_assess');
        return $collection;
    }

    /**
     * The person's context, and the activities where Vektra holds their data.
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
        $ids = [\context_user::instance($userid)->id];
        foreach ($api->subject_cmids($userid) as $cmid) {
            $ids[] = self::context_of($cmid, $userid)->id;
        }
        global $DB;
        [$sql, $params] = $DB->get_in_or_equal(array_values(array_unique($ids)), SQL_PARAMS_NAMED);
        $contextlist->add_from_sql("SELECT id FROM {context} WHERE id $sql", $params);
        return $contextlist;
    }

    /**
     * The people with data in an activity, or the owner of a user context who has data in Vektra.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        $api = self::api();
        if (!$api->in_use()) {
            return;
        }
        if ($context instanceof \context_module && self::is_discussion_module($context)) {
            $userlist->add_users($api->activity_subjects((int) $context->instanceid));
        } else if ($context instanceof \context_user && $api->subject_cmids((int) $context->instanceid)) {
            $userlist->add_user((int) $context->instanceid);
        }
    }

    /**
     * Export a person's data per context: each activity's record, and the transcripts' exact bytes.
     *
     * Everything that can be exported is written first; then a damaged transcript, or content Vektra cannot
     * export, fails the request with an explicit message (the DPO is notified).
     *
     * @param approved_contextlist $contextlist
     * @throws \moodle_exception
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        if (!$contextlist->count() || !self::api()->in_use()) {
            return;
        }
        $userid = (int) $contextlist->get_user()->id;
        $approved = array_map('intval', $contextlist->get_contextids());
        $kept = [];
        $keep = function (int $cmid) use (&$kept, $approved, $userid): bool {
            return $kept[$cmid] ??= in_array((int) self::context_of($cmid, $userid)->id, $approved, true);
        };
        $activities = self::api()->subject_activities($userid, $keep);
        $damaged = [];
        // Every transcript of the approved activities, even one whose session is no longer listed.
        $transcripts = [];
        foreach (self::api()->subject_transcripts($userid, $keep, $damaged) as $item) {
            $transcripts[$item['transcript_id']] = $item;
        }
        $path = get_string('privacy:path', 'local_vektradiscuss');
        foreach ($transcripts as $item) {
            $subcontext = [$path, get_string('privacy:session', 'local_vektradiscuss', (object) [
                'cmid' => $item['cmid'],
                'n' => $item['session_no'],
            ])];
            writer::with_context(self::context_of($item['cmid'], $userid))
                ->export_data($subcontext, (object) [
                    'cmid' => $item['cmid'],
                    'courseid' => $item['course_id'],
                    'session' => $item['session_no'],
                    'closed' => $item['closed_at'],
                    'sha256' => $item['sha256'],
                ])
                ->export_custom_file($subcontext, $item['filename'], $item['bytes']);
        }
        $damagedids = array_column($damaged, 'transcript_id');
        $missing = [];
        foreach ($activities as $activity) {
            $sessions = [];
            foreach ($activity['sessions'] as $session) {
                $tid = $session['transcript_id'];
                if ($tid !== null && !isset($transcripts[$tid]) && !in_array($tid, $damagedids, true)) {
                    $missing['transcript_missing'] = true;
                }
                if (!in_array($session['turns_unavailable'], [null, 'transcript_purged'], true)) {
                    $missing[$session['turns_unavailable']] = true;
                }
                $sessions[] = (object) [
                    'session' => $session['session_no'],
                    'state' => $session['state'],
                    'closereason' => $session['close_reason'],
                    'created' => $session['created_at'],
                    'deadline' => $session['deadline_at'],
                    'closed' => $session['closed_at'],
                    'transcript' => $tid !== null ? ($transcripts[$tid]['filename'] ?? null) : null,
                    'turns' => $session['turns'],
                    'turnsunavailable' => $session['turns_unavailable'],
                ];
            }
            writer::with_context(self::context_of($activity['cmid'], $userid))->export_data(
                [$path, get_string('privacy:activity', 'local_vektradiscuss', $activity['cmid'])],
                (object) [
                    'cmid' => $activity['cmid'],
                    'courseid' => $activity['course_id'],
                    'sessions' => $sessions,
                    'newsessions' => $activity['resets'],
                    'extraseconds' => $activity['extra_seconds'],
                    'extragranted' => $activity['extra_granted_at'],
                    'purgedsessions' => $activity['purged_sessions'],
                    'technicalrecords' => $activity['technical_records'],
                ]
            );
        }
        if ($damaged) {
            // Never export bytes that are not the stored transcript; everything else is written.
            throw new \moodle_exception('privacy_export_hash', 'local_vektradiscuss');
        }
        if ($missing) {
            // The DPO is told what is missing (a pending transcript: run the request again later).
            $reasons = implode(', ', array_keys($missing));
            throw new \moodle_exception('privacy_export_incomplete', 'local_vektradiscuss', '', $reasons);
        }
    }

    /**
     * Erase an activity's data for everyone, or a user context's data, then verify.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        $api = self::api();
        if (!$api->in_use()) {
            return;
        }
        if ($context instanceof \context_user) {
            self::erase_person($api, (int) $context->instanceid, [], true);
            return;
        }
        if (!$context instanceof \context_module || !self::is_discussion_module($context)) {
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
        $usercontext = false;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_module && self::is_discussion_module($context)) {
                $cmids[] = (int) $context->instanceid;
            } else if ($context instanceof \context_user && (int) $context->instanceid === $userid) {
                $usercontext = true;
            }
        }
        self::erase_person($api, $userid, $cmids, $usercontext);
    }

    /**
     * Erase the data of some people in an activity (or of a user context's owner), then verify.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        $api = self::api();
        $userids = array_map('intval', $userlist->get_userids());
        if (!$userids || !$api->in_use()) {
            return;
        }
        if ($context instanceof \context_user) {
            if (in_array((int) $context->instanceid, $userids, true)) {
                self::erase_person($api, (int) $context->instanceid, [], true);
            }
            return;
        }
        if (!$context instanceof \context_module || !self::is_discussion_module($context)) {
            return;
        }
        $cmid = (int) $context->instanceid;
        $api->erase_activity($cmid, $userids);
        if (array_intersect($userids, $api->activity_subjects($cmid))) {
            throw new \moodle_exception('privacy_erase_unverified', 'local_vektradiscuss');
        }
    }

    /**
     * Erase a person's data in the approved activities and, with the user context, in the activities deleted
     * from Moodle; then verify with the person's activity list.
     *
     * Always activity by activity, never subject-wide: Vektra's activity erasures remove the same rows, and a
     * subject-wide erasure could reach an activity where the person started a session after the list was
     * read, which the request did not approve. Every approved activity gets its erasure, even one the list
     * does not show, so that data kept as exam evidence is reported rather than skipped.
     *
     * @param privacy_api $api
     * @param int $userid
     * @param int[] $cmids Approved activities.
     * @param bool $usercontext Whether the person's own context is approved.
     */
    private static function erase_person(privacy_api $api, int $userid, array $cmids, bool $usercontext): void {
        $api->require_erase_key();
        $targets = $cmids;
        if ($usercontext) {
            foreach ($api->subject_cmids($userid) as $cmid) {
                if (!\context_module::instance($cmid, IGNORE_MISSING)) {
                    $targets[] = $cmid;
                }
            }
        }
        $targets = array_values(array_unique($targets));
        foreach ($targets as $cmid) {
            $api->erase_activity($cmid, [$userid]);
        }
        if ($targets && array_intersect($targets, $api->subject_cmids($userid))) {
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
