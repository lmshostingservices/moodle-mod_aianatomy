<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_aianatomy\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for mod_aianatomy.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describes stored personal data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'aianatomy_attempt', [
                'userid' => 'privacy:metadata:attempt:userid',
                'attempt' => 'privacy:metadata:attempt:attempt',
                'mode' => 'privacy:metadata:attempt:mode',
                'state' => 'privacy:metadata:attempt:state',
                'timestart' => 'privacy:metadata:attempt:timestart',
                'timefinish' => 'privacy:metadata:attempt:timefinish',
                'identcorrect' => 'privacy:metadata:attempt:identcorrect',
                'quizcorrect' => 'privacy:metadata:attempt:quizcorrect',
                'grade' => 'privacy:metadata:attempt:grade',
                'duration' => 'privacy:metadata:attempt:duration',
            ], 'privacy:metadata:attempt'
        );
        $collection->add_database_table(
            'aianatomy_response', [
                'kind' => 'privacy:metadata:response:kind',
                'structureid' => 'privacy:metadata:response:structureid',
                'answer' => 'privacy:metadata:response:answer',
                'correct' => 'privacy:metadata:response:correct',
                'tries' => 'privacy:metadata:response:tries',
                'timecreated' => 'privacy:metadata:response:timecreated',
            ], 'privacy:metadata:response'
        );
        $collection->add_database_table(
            'aianatomy_mastery', [
                'userid' => 'privacy:metadata:mastery:userid',
                'structureid' => 'privacy:metadata:mastery:structureid',
                'correct' => 'privacy:metadata:mastery:correct',
                'wrong' => 'privacy:metadata:mastery:wrong',
                'timemodified' => 'privacy:metadata:mastery:timemodified',
            ], 'privacy:metadata:mastery'
        );
        $collection->add_database_table(
            'aianatomy_structure', [
                'approvedby' => 'privacy:metadata:structure:approvedby',
                'timeapproved' => 'privacy:metadata:structure:timeapproved',
            ], 'privacy:metadata:structure'
        );
        $collection->add_external_location_link(
            'lmslabs', [
                'prompt' => 'privacy:metadata:lmslabs:prompt',
            ], 'privacy:metadata:lmslabs'
        );
        $collection->add_external_location_link(
            'lmslabs_tts', [
                'text' => 'privacy:metadata:lmslabs_tts:text',
            ], 'privacy:metadata:lmslabs_tts'
        );
        $collection->add_subsystem_link('core_grades', [], 'privacy:metadata:core_grades');
        return $collection;
    }

    /**
     * Contexts containing user data.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $base = "SELECT ctx.id
                   FROM {context} ctx
                   JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :ctxlevel
                   JOIN {modules} m ON m.id = cm.module AND m.name = :modname";
        $params = ['ctxlevel' => CONTEXT_MODULE, 'modname' => 'aianatomy', 'userid' => $userid];
        $contextlist = new contextlist();
        $contextlist->add_from_sql(
            $base . " JOIN {aianatomy_attempt} a ON a.aianatomyid = cm.instance
                                             WHERE a.userid = :userid", $params
        );
        $contextlist->add_from_sql(
            $base . " JOIN {aianatomy_mastery} ms ON ms.aianatomyid = cm.instance
                                             WHERE ms.userid = :userid", $params
        );
        $contextlist->add_from_sql(
            $base . " JOIN {aianatomy_structure} s ON s.aianatomyid = cm.instance
                                             WHERE s.approvedby = :userid", $params
        );
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $params = ['modname' => 'aianatomy', 'cmid' => $context->instanceid];
        $base = "FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module AND m.name = :modname";
        $userlist->add_from_sql(
            'userid', "SELECT a.userid $base
            JOIN {aianatomy_attempt} a ON a.aianatomyid = cm.instance WHERE cm.id = :cmid", $params
        );
        $userlist->add_from_sql(
            'userid', "SELECT ms.userid $base
            JOIN {aianatomy_mastery} ms ON ms.aianatomyid = cm.instance WHERE cm.id = :cmid", $params
        );
        $userlist->add_from_sql(
            'approvedby', "SELECT s.approvedby $base
            JOIN {aianatomy_structure} s ON s.aianatomyid = cm.instance WHERE cm.id = :cmid AND s.approvedby > 0", $params
        );
    }

    /**
     * Exports user data.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('aianatomy', $context->instanceid);
            if (!$cm) {
                continue;
            }
            $attempts = [];
            foreach ($DB->get_records(
                'aianatomy_attempt', ['aianatomyid' => $cm->instance, 'userid' => $user->id],
                'mode, attempt'
            ) as $a) {
                $responses = $DB->get_records('aianatomy_response', ['attemptid' => $a->id], 'id');
                $attempts[] = (object)[
                    'attempt' => $a->attempt,
                    'mode' => $a->mode,
                    'state' => $a->state,
                    'timestart' => transform::datetime($a->timestart),
                    'timefinish' => $a->timefinish ? transform::datetime($a->timefinish) : '-',
                    'identification' => $a->identcorrect . ' / ' . $a->identtotal,
                    'questions' => $a->quizcorrect . ' / ' . $a->quiztotal,
                    'grade' => $a->grade,
                    'duration' => $a->duration,
                    'responses' => array_values(
                        array_map(
                            fn($r) => (object)[
                                'kind' => $r->kind,
                                'structure' => $r->structureid,
                                'answer' => $r->answer,
                                'correct' => transform::yesno($r->correct),
                                'tries' => $r->tries,
                                'timecreated' => transform::datetime($r->timecreated),
                            ], $responses
                        )
                    ),
                ];
            }
            $mastery = array_values(
                array_map(
                    fn($m) => (object)[
                        'structure' => $m->structureid,
                        'correct' => $m->correct,
                        'wrong' => $m->wrong,
                        'timemodified' => transform::datetime($m->timemodified),
                    ], $DB->get_records('aianatomy_mastery', ['aianatomyid' => $cm->instance, 'userid' => $user->id])
                )
            );
            $approved = array_values(
                array_map(
                    fn($s) => (object)[
                        'structure' => $s->structureid,
                        'timeapproved' => transform::datetime($s->timeapproved),
                    ], $DB->get_records('aianatomy_structure', ['aianatomyid' => $cm->instance, 'approvedby' => $user->id])
                )
            );
            if (!$attempts && !$mastery && !$approved) {
                continue;
            }
            $data = helper::get_context_data($context, $user);
            $data->attempts = $attempts;
            $data->mastery = $mastery;
            $data->approvedcontent = $approved;
            writer::with_context($context)->export_data([], $data);
            helper::export_context_files($context, $user);
        }
    }

    /**
     * Deletes data for users matching a condition in an instance.
     *
     * @param int $instanceid
     * @param int[]|null $userids null = everyone
     */
    protected static function delete_for(int $instanceid, ?array $userids): void {
        global $DB;
        if ($userids === null) {
            \mod_aianatomy\local\manager::delete_user_data($instanceid);
            $DB->set_field('aianatomy_structure', 'approvedby', 0, ['aianatomyid' => $instanceid]);
            return;
        }
        foreach ($userids as $userid) {
            \mod_aianatomy\local\manager::delete_user_data($instanceid, (int)$userid);
            $DB->set_field(
                'aianatomy_structure', 'approvedby', 0, ['aianatomyid' => $instanceid,
                'approvedby' => $userid]
            );
        }
    }

    /**
     * Deletes all user data in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        if (!$context instanceof \context_module) {
            return;
        }
        if ($cm = get_coursemodule_from_id('aianatomy', $context->instanceid)) {
            self::delete_for((int)$cm->instance, null);
        }
    }

    /**
     * Deletes one user's data in the given contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_module && ($cm = get_coursemodule_from_id('aianatomy', $context->instanceid))) {
                self::delete_for((int)$cm->instance, [$userid]);
            }
        }
    }

    /**
     * Deletes data for several users in a context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module || !$userlist->get_userids()) {
            return;
        }
        if ($cm = get_coursemodule_from_id('aianatomy', $context->instanceid)) {
            self::delete_for((int)$cm->instance, $userlist->get_userids());
        }
    }
}
