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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

defined('MOODLE_INTERNAL') || die();

/**
 * Core library for the YesWeCanQuiz plugin.
 *
 * Controls session behaviour and navigation alterations for the public user.
 *
 * @package    local_yeswecanquiz
 * @copyright  2025 Ikrame Saadi (@ikramagix) <hello@yeswecanquiz.eu>
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

 /**
 * Enforce quiz session rules for the public user.
 *
 * - On /mod/quiz/view.php, mark any unfinished attempt as finished and redirect.
 * - Outside quiz pages, log out the public user.
 *
 * @global \stdClass         $USER   Current user object.
 * @global \moodle_database  $DB     Moodle database API.
 * @global string            $SCRIPT The current script path.
 * @return void
 */

function local_yeswecanquiz_session_control() {
    global $USER, $DB, $SCRIPT;

    // Get the public user ID from plugin settings.
    $publicuserid = get_config('local_yeswecanquiz', 'publicuserid');
    if (!$publicuserid) {
        return;
    }

    // Use Moodle’s session-mode cache to avoid looping without using $_SESSION object.
$cache = \cache::make('local_yeswecanquiz', 'session');


    // CASE 1: On quiz view page.
    if (strpos($SCRIPT, '/mod/quiz/view.php') !== false) {

        // Auto-login as public user if necessary.
        if (!isloggedin() || isguestuser()) {
            $publicuser = $DB->get_record('user', array('id' => $publicuserid, 'deleted' => 0, 'suspended' => 0));
            if ($publicuser) {
                complete_user_login($publicuser);
                session_write_close();
                redirect(new moodle_url($SCRIPT, $_GET));
            }
        }
        // Now, if we are the public user…
        if (isloggedin() && !isguestuser() && $USER->id === $publicuserid) {
            if (isset($_GET['id'])) {
                $cmid = required_param('id', PARAM_INT);
                // Only process once per quiz view to avoid looping.
                $cache = \cache::make('local_yeswecanquiz', 'session');
                if (!$cache->has((string)$cmid)) {
                    // Get course module and quiz records.
                    $cm   = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
                    $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);

                    // Update any unfinished attempts (state <> "finished") to "finished".
                    if ($attempts = $DB->get_records_select(
                        'quiz_attempts',
                        'quiz = ? AND userid = ? AND state <> ?',
                        [$quiz->id, $publicuserid, 'finished']
                    )) {
                        foreach ($attempts as $attempt) {
                            $DB->set_field('quiz_attempts', 'state', 'finished', ['id' => $attempt->id]);
                        }
                    }

                    // Mark that we have processed this quiz for this session.
                    $cache->set((string)$cmid, true);

                    // Redirect to the attempt page so Moodle creates a new attempt.
                    $attempturl = new moodle_url(
                        '/mod/quiz/attempt.php',
                        ['attempt' => 0, 'id' => $cmid]
                    );
                    redirect($attempturl);
                }
            }
        }
    }
    // CASE 2: On other /mod/quiz/ pages (for example attempt.php).
    else if (strpos($SCRIPT, '/mod/quiz/') !== false) {
        if (!isloggedin() || isguestuser()) {
            $publicuser = $DB->get_record('user', array('id' => $publicuserid, 'deleted' => 0, 'suspended' => 0));
            if ($publicuser) {
                complete_user_login($publicuser);
                session_write_close();
                redirect(new moodle_url($SCRIPT, $_GET));
            }
        }
    }
    // CASE 3: Outside /mod/quiz/ pages.
    else {
        if (isloggedin() && !isguestuser() && $USER->id == $publicuserid) {
            require_logout();
            redirect(new moodle_url($SCRIPT, $_GET));
        }
    }
}

/**
 * Hook to run before HTTP headers are sent.
 *
 * Calls session control logic for public-user enforcement.
 *
 * @return void
 */

function local_yeswecanquiz_before_http_headers() {
    local_yeswecanquiz_session_control();
}

/**
 * Extend the course navigation for the public user.
 *
 * Adjusts or hides navigation nodes to enforce the new-attempt policy.
 *
 * @param  \global_navigation  $navigation The navigation tree.
 * @return void
 */

function local_yeswecanquiz_extend_navigation(global_navigation $navigation) {
    local_yeswecanquiz_session_control();
}
