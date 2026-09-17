<?php

return [
    'welcomepage' => 'App/views/home.view.php',
    'logout' => 'App/controllers/logout.php',
    'google-login' => 'App/controllers/google-login.php',
    'session-check' => 'App/controllers/session-check.php',

    'mentor-dashboard' => 'App/views/mentorpage/index.php',
    'mentor-settings' => 'App/views/settings/index.php',
    'mentor-upcoming' => 'App/views/mentorpage/upcoming_session.php',
    'mentor-verification' => 'App/views/mentorpage/verification.php',
    'mentor-feedback' => 'App/views/mentorpage/feedback.php',
    'mentor-calendar' => 'App/views/mentorpage/calendar.php',
    'mentor-requests' => 'App/views/mentorpage/session_request.php',
    'mentor-completed' => 'App/views/mentorpage/completed_session.php',
    'mentor-profile' => 'App/views/mentorpage/profile.php',
    'mentor-history' => 'App/views/mentorpage/session_history.php',
    'mentor-groups' => 'App/views/mentorpage/group_sessions.php',
    'mentor-check-status' => 'App/views/mentorpage/action/check_status.php',
    'mentor-update-profile' => 'App/views/mentorpage/action/update_profile.php',

    'mentee-dashboard' => 'App/views/menteepage/index.php',
    'mentee-settings' => 'App/views/settings/index.php',
    // 'mentee-feedback' is the mentee's My Feedback page (reviews their
    // mentors left about them), mirroring 'mentor-feedback'. The review
    // FORM lives on 'mentee-review', the mirror of 'mentor-review'.
    'mentee-feedback' => 'App/views/menteepage/feedback.php',
    'mentee-review'   => 'App/views/feedback/review.php',
    'mentee-verification' => 'App/views/menteepage/verification.php',
    'mentee-sessions' => 'App/views/menteepage/sessions.php',
    'mentee-calendar' => 'App/views/menteepage/mentee_calendar.php',
    'mentee-request' => 'App/views/menteepage/request.php',
    'mentee-profile' => 'App/views/menteepage/profile.php',
    'mentee-find' => 'App/views/menteepage/find_mentor.php',
    'mentee-view-mentor' => 'App/views/menteepage/view/view_mentor.php',
    'get-availability' => 'App/views/menteepage/view/get_availability.php',
    'get-times' => 'App/views/menteepage/view/get_times.php',
    'get-group-sessions' => 'App/views/menteepage/view/get_group_sessions.php',
    'save-booking' => 'App/views/menteepage/view/save_booking.php',
    'save-report' => 'App/views/menteepage/view/save_report.php',
    'mentee-check-status' => 'App/views/menteepage/action/check_status.php',
    'mentee-update-profile' => 'App/views/menteepage/action/update_profile.php',
    'mentee-goals-action' => 'App/views/menteepage/action/goals.php',
    'mentee-mentor-slots' => 'App/views/menteepage/action/mentor_slots.php',

    'admin-dashboard' => 'App/views/admin/index.php',
    'admin-verify' => 'App/views/admin/verify.php',
    'admin-users' => 'App/views/admin/users.php',
    // The admin's view of a single account. Mentors have a public profile page
    // and mentees do not, so this is the one place an admin can inspect any
    // member regardless of role.
    'admin-user' => 'App/views/admin/user_view.php',
    'admin-action-verify' => 'App/views/admin/action_verify.php',
    'admin-action-block' => 'App/views/admin/action_block.php',
    'admin-action-unblock' => 'App/views/admin/action_unblock.php',
    'admin-action-restrict' => 'App/views/admin/action_restrict.php',
    'admin-action-resolve' => 'App/views/admin/action_resolve.php',
    // Repairs an account left with no role. See action_role.php for why it
    // only ever fills an empty one.
    'admin-action-role' => 'App/views/admin/action_role.php',

    'video-room' => 'App/views/VideoConferencing/room.php',
    'video-end' => 'App/views/VideoConferencing/end_session.php',
    'video-join' => 'App/views/VideoConferencing/join_check.php',

    'messages' => 'App/views/Messages/message.php',
    'messages-send' => 'App/views/Messages/send_message.php',
    'messages-get' => 'App/views/Messages/get_messages.php',

    // Notifications
    'notifications-count'     => 'App/views/notifications/count.php',
    'notifications-get'       => 'App/views/notifications/get.php',
    'notifications-read'      => 'App/views/notifications/mark_read.php',
    'notifications-read-all'  => 'App/views/notifications/mark_all_read.php',

    // Assessments — mentors write them, their mentees take them
    'assessments'         => 'App/views/assessments/index.php',
    'assessment-create'   => 'App/views/assessments/create.php',
    'assessment-save'     => 'App/views/assessments/save.php',
    'assessment-delete'   => 'App/views/assessments/delete.php',
    'assessment-take'     => 'App/views/assessments/take.php',
    'assessment-answer'   => 'App/views/assessments/answer.php',
    'assessment-submit'   => 'App/views/assessments/submit.php',
    'assessment-results'  => 'App/views/assessments/results.php',

    // Resources — shared study files (mentee + mentor, filed by club)
    'resources'           => 'App/views/includes/resources.php',
    'resource-upload'     => 'App/views/resources/upload.php',
    'resource-download'   => 'App/views/resources/download.php',
    'resource-bookmark'   => 'App/views/resources/bookmark.php',
    'resource-delete'     => 'App/views/resources/delete.php',

    // Matching (replaces "Partner")
    'mentee-matching'         => 'App/views/menteepage/matching.php',

    // Profile — About Me, interests, skills (shared by both roles)
    'profile-save'            => 'App/views/profile/save.php',
    'onboarding'              => 'App/views/onboarding/index.php',
    'login'                   => 'App/views/auth/login.php',
    'signup'                  => 'App/views/auth/signup.php',
    'forgot-password'         => 'App/views/auth/forgot_password.php',
    'reset-password'          => 'App/views/auth/reset_password.php',

    // Leaderboard — top mentors, ranked from real session/feedback data
    'leaderboard'             => 'App/views/leaderboard/index.php',

    // Google Calendar — real two-way link, not a file export
    'gcal-connect'            => 'App/views/calendar/gcal_connect.php',
    'gcal-callback'           => 'App/views/calendar/gcal_callback.php',
    'gcal-action'             => 'App/views/calendar/gcal_action.php',

    // Feedback — one review page, both directions (mentee↔mentor)
    'feedback-save'           => 'App/views/feedback/save.php',
    'mentor-review'           => 'App/views/feedback/review.php',
    // Where room.php / end_session.php send the mentee after a call.
    'mentee-submit-feedback'  => 'App/views/feedback/review.php',

    // Account settings (shared: mentor + mentee)
    'account-update-email'    => 'App/views/settings/update_email.php',
    'account-update-password' => 'App/views/settings/update_password.php',
    'account-update-notif-pref' => 'App/views/settings/update_notification_pref.php',
    'account-update-account'  => 'App/views/settings/update_account.php',
    'account-update-privacy'  => 'App/views/settings/update_privacy.php',
    'account-export-data'     => 'App/views/settings/export_data.php',
    'settings'                => 'App/views/settings/index.php',
    'account-delete'          => 'App/views/settings/delete_account.php',
    'session-ics'              => 'App/views/settings/session_ics.php',

    // Badges & Certificates (admin). Badges are recognition the platform can
    // award on its own; certificates are documents an admin issues by hand.
    'admin-badges'            => 'App/views/admin/badges.php',
    'admin-badge-holders'     => 'App/views/admin/badge_holders.php',
    'admin-action-badge'      => 'App/views/admin/action_badge.php',
    'admin-award-badge'       => 'App/views/admin/action_award_badge.php',
    'admin-check-badges'      => 'App/views/admin/action_check_badges.php',

    'admin-certificates'         => 'App/views/admin/certificates.php',
    'admin-certificates-issued'  => 'App/views/admin/certificates_issued.php',
    'admin-action-certificate'   => 'App/views/admin/action_certificate.php',
    // The certificate itself, rendered on demand for the person it was issued
    // to (or an admin). There is no stored file — see certificates/view.php.
    'certificate-view'           => 'App/views/certificates/view.php',

    // Missed session detection
    'cron-missed-sessions'    => 'App/views/cron/detect_missed_sessions.php',

    // Admin login (separate from main portal)
    'admin-login'             => 'App/views/admin/login.php',
    'admin-signup'            => 'App/views/admin/signup.php',

    // Admin sessions — the list, the calendar and the reports are three views
    // of one set of rows (see App/repositories/AdminSessionRepository.php).
    'admin-sessions'          => 'App/views/admin/sessions.php',
    'admin-sessions-calendar' => 'App/views/admin/sessions_calendar.php',
    'admin-sessions-reports'  => 'App/views/admin/sessions_reports.php',
    'admin-sessions-export'   => 'App/views/admin/sessions_export.php',
    'admin-action-session'    => 'App/views/admin/action_session.php',

    // System Settings. Seven screens, one action handler keyed by a section
    // field (see App/views/includes/settings_store.php for what each key does).
    'admin-settings'              => 'App/views/admin/settings.php',
    'admin-settings-security'     => 'App/views/admin/settings_security.php',
    'admin-settings-email'        => 'App/views/admin/settings_email.php',
    'admin-settings-appearance'   => 'App/views/admin/settings_appearance.php',
    'admin-settings-integrations' => 'App/views/admin/settings_integrations.php',
    'admin-settings-backup'       => 'App/views/admin/settings_backup.php',
    'admin-settings-backup-run'   => 'App/views/admin/settings_backup_run.php',
    'admin-settings-logs'         => 'App/views/admin/settings_logs.php',
    'admin-settings-logs-export'  => 'App/views/admin/settings_logs_export.php',
    'admin-action-settings'       => 'App/views/admin/action_settings.php',

    // Announcements — the admin writes them, mentors and mentees read them.
    // One member page serves both roles; the query decides what each may see
    // (see App/views/includes/announcement_data.php).
    'announcements'            => 'App/views/announcements/index.php',
    'admin-announcements'      => 'App/views/admin/announcements.php',
    'admin-action-announcement' => 'App/views/admin/action_announcement.php',

    // Admin assessments — monitoring only. Every assessment belongs to the
    // mentor who wrote it, so these three screens list and measure; they do
    // not author (see admin/includes/assessment_data.php).
    'admin-assessments'           => 'App/views/admin/assessments.php',
    'admin-assessments-results'   => 'App/views/admin/assessments_results.php',
    'admin-assessments-questions' => 'App/views/admin/assessments_questions.php',
    'admin-assessments-export'    => 'App/views/admin/assessments_export.php',
    'admin-action-assessment'     => 'App/views/admin/action_assessment.php',

    // Reports & Analytics. The summary is the overview an admin opens first;
    // Platform analytics is the older, deeper breakdown of mentors and badges.
    // Both read only — they measure, they do not change anything.
    'admin-reports'           => 'App/views/admin/reports.php',
    'admin-reports-export'    => 'App/views/admin/reports_export.php',
    'admin-analytics'         => 'App/views/admin/analytics.php',

    // The web app manifest, built from BASE_URL and url() so it follows the
    // install's folder and route secret. Linked from includes/pwa.php.
    'pwa-manifest'            => 'App/views/includes/manifest.php',
];
