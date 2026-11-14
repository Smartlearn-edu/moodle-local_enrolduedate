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

namespace local_enrolduedate\local;

use stdClass;

/**
 * Sends personalised Moodle notifications when activity triggers unlock due dates.
 *
 * @package    local_enrolduedate
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notification_helper {
    /**
     * Sends a 'duedateunlocked' notification if eligible and enabled.
     *
     * @param stdClass $rule Rule record.
     * @param stdClass $course Course record.
     * @param stdClass $user Target student user record.
     * @param stdClass $applied Applied tracking record.
     * @param string $activityname Formatted activity display name.
     * @return bool True if notification was sent.
     */
    public static function send_if_eligible(
        stdClass $rule,
        stdClass $course,
        stdClass $user,
        stdClass $applied,
        string $activityname
    ): bool {
        $enabled = get_config('local_enrolduedate', 'enablenotifications');
        if ($enabled !== false && (int)$enabled === 0) {
            return false;
        }

        $activitytriggers = [
            rule_repository::TRIGGER_ACTIVITY_COMPLETED,
            rule_repository::TRIGGER_ACTIVITY_VIEWED,
        ];
        if (!in_array($rule->triggertype, $activitytriggers, true)) {
            return false;
        }

        if (!empty($applied->notified)) {
            return false;
        }

        if (empty($applied->computeddue) || (int)$applied->computeddue <= time()) {
            return false;
        }

        if (!isset($user->mailformat) || !isset($user->email)) {
            $fulluser = \core_user::get_user((int)$user->id);
            if (!$fulluser) {
                return false;
            }
            $user = $fulluser;
        }

        $tz = $user->timezone ?? '99';
        $datelines = [];
        if (!empty($applied->computedopen)) {
            $datelines[] = get_string(
                'notification_open_line',
                'local_enrolduedate',
                userdate((int)$applied->computedopen, '', $tz)
            );
        }
        $formatteddue = userdate((int)$applied->computeddue, '', $tz);
        $datelines[] = get_string('notification_due_line', 'local_enrolduedate', $formatteddue);
        if (!empty($applied->computedcutoff)) {
            $datelines[] = get_string(
                'notification_cutoff_line',
                'local_enrolduedate',
                userdate((int)$applied->computedcutoff, '', $tz)
            );
        }

        $url = new \moodle_url('/mod/' . $rule->modname . '/view.php', ['id' => $rule->cmid]);
        $a = (object)[
            'firstname' => $user->firstname ?? '',
            'activityname' => $activityname,
            'coursename' => format_string($course->fullname ?? '', true),
            'dates' => implode("\n", $datelines),
            'duedate' => $formatteddue,
            'url' => $url->out(false),
        ];

        $message = new \core\message\message();
        $message->component = 'local_enrolduedate';
        $message->name = 'duedateunlocked';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->courseid = (int)$course->id;
        $message->subject = get_string('notification_unlocked_subject', 'local_enrolduedate', $a);
        $message->fullmessage = get_string('notification_unlocked_body', 'local_enrolduedate', $a);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = text_to_html($message->fullmessage);
        $message->smallmessage = get_string('notification_unlocked_small', 'local_enrolduedate', $a);
        $message->notification = 1;
        $message->contexturl = $url->out(false);
        $message->contexturlname = $activityname;

        $sentid = message_send($message);
        return $sentid !== false;
    }
}
