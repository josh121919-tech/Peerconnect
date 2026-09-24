<?php

/**
 * DataExportRepository — what Settings → Data Privacy → Download My Data
 * gives a member: everything PeerConnect holds about their account, one
 * section per kind of record.
 *
 * Secrets are never part of it: no password hash, no sign-in or "Remember
 * me" tokens, no password-reset links and no Google Calendar tokens.
 */
class DataExportRepository extends Repository
{
    /** Every section, keyed as the JSON file names them, or null when the account does not exist. */
    public static function forUser(mysqli $con, int $userId): ?array
    {
        $account = self::account($con, $userId);
        if ($account === null) {
            return null;
        }
        $one  = fn(string $sql) => self::typedRow($con, $sql, 'i', [$userId]);
        $many = fn(string $sql) => self::typedRows($con, $sql, 'i', [$userId]);
        $both = fn(string $sql) => self::typedRows($con, $sql, 'ii', [$userId, $userId]);

        return [
            'account'      => $account,
            'profile'      => $one("SELECT * FROM profile WHERE user_id = ?"),
            'verification' => $many("
                SELECT verification_id, full_name, student_id, course, year_level, club, expertise, status, submitted_at, reviewed_at
                FROM user_verifications WHERE user_id = ?
            "),
            'sessions' => $both("
                SELECT sr.request_id, sr.subject, sr.session_date, sr.status, sr.message, sr.rejection_reason, sr.completed_at,
                       CONCAT(mentor.firstname,' ',mentor.lastname) AS mentor,
                       CONCAT(mentee.firstname,' ',mentee.lastname) AS mentee
                FROM session_requests sr
                JOIN users mentor ON mentor.user_id = sr.mentor_id
                JOIN users mentee ON mentee.user_id = sr.mentee_id
                WHERE sr.mentor_id = ? OR sr.mentee_id = ?
                ORDER BY sr.session_date DESC
            "),
            'feedback_given' => $many("
                SELECT feedback_id, session_id, rating, comment, communication, efficiency, knowledge, skill, created_at
                FROM feedback WHERE mentee_id = ? ORDER BY created_at DESC
            "),
            'feedback_received' => $many("
                SELECT feedback_id, session_id, rating, comment, created_at
                FROM feedback WHERE mentor_id = ? ORDER BY created_at DESC
            "),
            'messages' => $both("
                SELECT id AS message_id, sender_id, receiver_id, content, is_read, created_at
                FROM messages WHERE sender_id = ? OR receiver_id = ?
                ORDER BY created_at DESC
            "),
            'assessment_attempts' => $many("
                SELECT at.attempt_id, a.title, at.score, at.total_points, at.status, at.started_at, at.submitted_at
                FROM assessment_attempts at JOIN assessments a ON a.assessment_id = at.assessment_id
                WHERE at.mentee_id = ?
            "),
            'resources_uploaded' => $many("
                SELECT resource_id, club, title, description, original_name, file_size, download_count, created_at
                FROM resources WHERE uploader_id = ?
            "),
            'notification_preferences' => $one("SELECT * FROM notification_preferences WHERE user_id = ?"),
            'privacy_settings'         => $one("SELECT * FROM privacy_settings WHERE user_id = ?"),
            'activity_log' => $many("
                SELECT activity, log_date FROM logs WHERE email = (SELECT email FROM users WHERE user_id = ?)
                ORDER BY log_date DESC LIMIT 200
            "),

            // Sections the download used to leave out while calling itself the full copy.
            'interests_and_skills' => $many("
                SELECT tag_type, tag, created_at FROM user_tags WHERE user_id = ? ORDER BY tag_type, tag_id
            "),
            'mentee_preferences' => $one("
                SELECT preferred_topic, learning_goal, skill_level, preferred_schedule, communication_style, session_type, updated_at
                FROM mentee_preferences WHERE mentee_id = ?
            "),
            'goals' => $many("
                SELECT goal_id, title, description, status, created_at, completed_at
                FROM goals WHERE mentee_id = ? ORDER BY created_at DESC
            "),
            'reviews_from_mentors' => $many("
                SELECT review_id, session_id, rating, preparedness, participation, communication, receptiveness,
                       note_preparedness, note_participation, note_communication, note_receptiveness, note_overall,
                       comment, created_at
                FROM mentee_reviews WHERE mentee_id = ? ORDER BY created_at DESC
            "),
            'reviews_written_about_mentees' => $many("
                SELECT review_id, session_id, rating, preparedness, participation, communication, receptiveness,
                       note_preparedness, note_participation, note_communication, note_receptiveness, note_overall,
                       comment, created_at
                FROM mentee_reviews WHERE mentor_id = ? ORDER BY created_at DESC
            "),
            'review_drafts' => $many("
                SELECT draft_id, direction, session_id, payload, updated_at
                FROM feedback_drafts WHERE author_id = ? ORDER BY updated_at DESC
            "),
            'session_attendance' => $many("
                SELECT session_id, role, joined_at FROM session_attendance WHERE user_id = ? ORDER BY joined_at DESC
            "),
            // One row per visit to a call, which is how long each session
            // actually counted for. Personal data, so it belongs in the export
            // alongside the attendance roster it sits next to.
            'session_presence' => $many("
                SELECT session_id, role, joined_at, last_seen_at, left_at
                FROM session_presence WHERE user_id = ? ORDER BY joined_at DESC
            "),
            'availability' => $many("
                SELECT availability_id, subject, date, start_time, duration, capacity, session_type, about, topics, created_at
                FROM availability WHERE mentor_id = ? ORDER BY date DESC, start_time DESC
            "),
            'assessments_created' => $many("
                SELECT assessment_id, title, topic, status, created_at, published_at
                FROM assessments WHERE mentor_id = ? ORDER BY created_at DESC
            "),
            'badges' => $many("
                SELECT b.name, b.description, ub.awarded_at
                FROM user_badges ub JOIN badges b ON b.badge_id = ub.badge_id
                WHERE ub.user_id = ? ORDER BY ub.awarded_at DESC
            "),
            'certificates' => $many("
                SELECT uc.cert_id, ct.name, uc.achievement, uc.awarded_at
                FROM user_certificates uc LEFT JOIN certificate_templates ct ON ct.template_id = uc.template_id
                WHERE uc.user_id = ? ORDER BY uc.awarded_at DESC
            "),
            'saved_resources' => $many("
                SELECT r.resource_id, r.title, r.club, rb.created_at AS saved_at
                FROM resource_bookmarks rb JOIN resources r ON r.resource_id = rb.resource_id
                WHERE rb.user_id = ? ORDER BY rb.created_at DESC
            "),
            'reports_filed' => $many("
                SELECT report_id, issue_type, description, status, created_at
                FROM reports WHERE reported_by = ? ORDER BY created_at DESC
            "),
            'notifications' => $many("
                SELECT type, title, message, is_read, created_at
                FROM notifications WHERE user_id = ? ORDER BY created_at DESC
            "),
            'google_calendar' => $one("
                SELECT google_email, last_synced_at, created_at AS connected_at
                FROM google_calendar_links WHERE user_id = ?
            "),
        ];
    }

    private static function account(mysqli $con, int $userId): ?array
    {
        return self::typedRow($con, "
            SELECT user_id, firstname, middlename, lastname, suffix, email, username, role, verified, status, created_at
            FROM users WHERE user_id = ?
        ", 'i', [$userId]);
    }
}
