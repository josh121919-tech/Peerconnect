-- ═══════════════════════════════════════════════════════════════════════
-- PeerConnect — Database Migration File
-- Run in order. Safe to run multiple times (IF NOT EXISTS / IGNORE).
-- ═══════════════════════════════════════════════════════════════════════

-- ─── PHASE 0: Security ───────────────────────────────────────────────────────

-- Add missing columns to users table for full name support
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS middlename VARCHAR(100) DEFAULT '' AFTER firstname,
    ADD COLUMN IF NOT EXISTS suffix     VARCHAR(30)  DEFAULT '' AFTER lastname;

-- Add reason + restricted_by audit columns to restrictions
ALTER TABLE restrictions
    ADD COLUMN IF NOT EXISTS reason        TEXT         AFTER user_id,
    ADD COLUMN IF NOT EXISTS restricted_by INT UNSIGNED  AFTER reason,
    ADD COLUMN IF NOT EXISTS restricted_at DATETIME DEFAULT CURRENT_TIMESTAMP AFTER restricted_by;

-- Add reason column to blocks
ALTER TABLE blocks
    ADD COLUMN IF NOT EXISTS reason TEXT AFTER user_id;

-- Ensure feedback table has all effectiveness columns
ALTER TABLE feedback
    ADD COLUMN IF NOT EXISTS session_id   INT UNSIGNED  AFTER feedback_id,
    ADD COLUMN IF NOT EXISTS communication INT DEFAULT 0 AFTER comment,
    ADD COLUMN IF NOT EXISTS efficiency    INT DEFAULT 0 AFTER communication,
    ADD COLUMN IF NOT EXISTS knowledge     INT DEFAULT 0 AFTER efficiency,
    ADD COLUMN IF NOT EXISTS skill         INT DEFAULT 0 AFTER knowledge;

-- Add unique constraint to prevent duplicate feedback per session
ALTER TABLE feedback
    ADD UNIQUE IF NOT EXISTS ux_session_mentee (session_id, mentee_id);

-- Add status column to session_requests if missing
ALTER TABLE session_requests
    ADD COLUMN IF NOT EXISTS rejection_reason TEXT    AFTER status,
    ADD COLUMN IF NOT EXISTS missed_by        ENUM('none','mentor','mentee','both') DEFAULT 'none' AFTER rejection_reason,
    ADD COLUMN IF NOT EXISTS completed_at     DATETIME AFTER missed_by;

-- ─── PHASE 1: Notifications ──────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `notifications` (
    `notification_id` INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `user_id`         INT UNSIGNED     NOT NULL,
    `type`            VARCHAR(60)      NOT NULL COMMENT 'e.g. session_approved, session_rejected, verification_approved, missed_session',
    `title`           VARCHAR(255)     NOT NULL,
    `message`         TEXT             NOT NULL,
    `link`            VARCHAR(500)     DEFAULT NULL COMMENT 'URL to redirect to on click',
    `is_read`         TINYINT(1)       NOT NULL DEFAULT 0,
    `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`notification_id`),
    KEY `idx_user_unread` (`user_id`, `is_read`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── PHASE 2: Certificates & Badges ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `certificate_templates` (
    `template_id`  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `name`         VARCHAR(255)  NOT NULL,
    `description`  TEXT,
    `template_path` VARCHAR(500) NOT NULL COMMENT 'Path to uploaded template image/PDF',
    `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
    `created_by`   INT UNSIGNED,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `badges` (
    `badge_id`     INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `name`         VARCHAR(100)  NOT NULL,
    `description`  TEXT,
    `icon_path`    VARCHAR(500)  NOT NULL,
    `criteria_type` ENUM('sessions_completed','avg_rating','manual','top_mentor','community') NOT NULL DEFAULT 'manual',
    `criteria_value` INT DEFAULT NULL COMMENT 'e.g. 10 for "10 sessions"',
    `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
    `created_by`   INT UNSIGNED,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`badge_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_badges` (
    `user_badge_id` INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED  NOT NULL,
    `badge_id`      INT UNSIGNED  NOT NULL,
    `awarded_by`    INT UNSIGNED  DEFAULT NULL COMMENT 'NULL = auto-awarded',
    `awarded_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_badge_id`),
    UNIQUE KEY `ux_user_badge` (`user_id`, `badge_id`),
    KEY `fk_ub_badge` (`badge_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_certificates` (
    `cert_id`       INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED  NOT NULL,
    `template_id`   INT UNSIGNED  NOT NULL,
    `generated_path` VARCHAR(500) NOT NULL COMMENT 'Path to generated certificate file',
    `awarded_by`    INT UNSIGNED  DEFAULT NULL,
    `awarded_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `achievement`   VARCHAR(255)  NOT NULL,
    PRIMARY KEY (`cert_id`),
    KEY `fk_uc_user` (`user_id`),
    KEY `fk_uc_template` (`template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── PHASE 3: AI Matching Preferences ────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `mentor_scores` (
    `score_id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `mentor_id`        INT UNSIGNED  NOT NULL,
    `avg_rating`       DECIMAL(3,2)  DEFAULT 0.00,
    `total_sessions`   INT           DEFAULT 0,
    `completion_rate`  DECIMAL(5,2)  DEFAULT 0.00 COMMENT 'completed / total approved %',
    `response_time_h`  DECIMAL(6,2)  DEFAULT NULL COMMENT 'avg hours to approve request',
    `effectiveness_score` DECIMAL(5,2) DEFAULT 0.00 COMMENT 'composite 0-100',
    `recommendation_score` DECIMAL(6,2) DEFAULT 0.00 COMMENT 'final AI sort score',
    `last_calculated`  DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`score_id`),
    UNIQUE KEY `ux_mentor_score` (`mentor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mentee_preferences` (
    `pref_id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `mentee_id`        INT UNSIGNED  NOT NULL,
    `preferred_topic`  VARCHAR(255)  DEFAULT NULL,
    `learning_goal`    TEXT          DEFAULT NULL,
    `skill_level`      ENUM('beginner','intermediate','advanced') DEFAULT 'beginner',
    `preferred_schedule` VARCHAR(100) DEFAULT NULL COMMENT 'e.g. weekday mornings',
    `communication_style` ENUM('structured','casual','mixed') DEFAULT 'mixed',
    `session_type`     ENUM('one_on_one','group','any') DEFAULT 'any',
    `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`pref_id`),
    UNIQUE KEY `ux_mentee_pref` (`mentee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── PHASE 4: Missed Sessions Tracking ───────────────────────────────────────

CREATE TABLE IF NOT EXISTS `missed_session_logs` (
    `log_id`       INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `session_id`   INT UNSIGNED  NOT NULL,
    `missed_by`    ENUM('mentor','mentee','both') NOT NULL,
    `detected_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `notified`     TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (`log_id`),
    UNIQUE KEY `ux_missed_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Categories (admin-managed, used in find_mentor rail) ───────────────────

CREATE TABLE IF NOT EXISTS `categories` (
    `category_id`  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `name`         VARCHAR(120)     NOT NULL,
    `slug`         VARCHAR(120)     NOT NULL COMMENT 'URL-safe identifier',
    `icon`         VARCHAR(50)      NOT NULL DEFAULT 'explore' COMMENT 'pc_icon() key',
    `sort_order`   INT              NOT NULL DEFAULT 0,
    `is_active`    TINYINT(1)       NOT NULL DEFAULT 1,
    `created_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`category_id`),
    UNIQUE KEY `ux_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default categories (match the screenshot exactly)
INSERT IGNORE INTO `categories` (`category_id`, `name`, `slug`, `icon`, `sort_order`) VALUES
(1,  'All',            'all',           'home',     0),
(2,  'New',            'new',           'partner',  1),
(3,  'Available ASAP', 'available-asap','bookings', 2),
(4,  'Notable',        'notable',       'verify',   3),
(5,  'AI',             'ai',            'explore',  4),
(6,  'Soft Skills',    'soft-skills',   'explore',  5),
(7,  'Design',         'design',        'explore',  6),
(8,  'Product',        'product',       'explore',  7),
(9,  'Engineering',    'engineering',   'explore',  8),
(10, 'Marketing',      'marketing',     'explore',  9),
(11, 'Data Science',   'data-science',  'explore',  10),
(12, 'Content Writing','content-writing','explore', 11),
(13, 'No/Low Code',    'no-low-code',   'explore',  12),
(14, 'Mathematics Club','mathematics-club','explore',13),
(15, 'Science Club',   'science-club',  'explore',  14);

-- The personal note a mentee writes when requesting a session. Both request
-- paths write it: the "Request Mentorship" modal on Find a Mentor and the
-- 3-step booking modal on a mentor's profile (whose Notes box used to be
-- collected and then silently dropped, since there was nowhere to put it).
ALTER TABLE session_requests
    ADD COLUMN IF NOT EXISTS `message` TEXT NULL AFTER `subject`;

-- ─── Indexes for performance ──────────────────────────────────────────────────

-- session_requests
ALTER TABLE session_requests
    ADD INDEX IF NOT EXISTS idx_mentor_status (`mentor_id`, `status`),
    ADD INDEX IF NOT EXISTS idx_mentee_status (`mentee_id`, `status`),
    ADD INDEX IF NOT EXISTS idx_session_date  (`session_date`);

-- feedback
ALTER TABLE feedback
    ADD INDEX IF NOT EXISTS idx_feedback_mentor (`mentor_id`),
    ADD INDEX IF NOT EXISTS idx_feedback_mentee (`mentee_id`);

-- availability
ALTER TABLE availability
    ADD INDEX IF NOT EXISTS idx_avail_mentor_date (`mentor_id`, `date`);

-- ─── Default Badges ──────────────────────────────────────────────────────────

INSERT IGNORE INTO `badges` (`badge_id`, `name`, `description`, `icon_path`, `criteria_type`, `criteria_value`) VALUES
(1, 'First Session',      'Completed your first mentoring session',  'badges/first_session.svg',  'sessions_completed', 1),
(2, 'Rising Mentor',      'Completed 10 mentoring sessions',          'badges/rising_mentor.svg',  'sessions_completed', 10),
(3, 'Experienced Mentor', 'Completed 25 mentoring sessions',          'badges/experienced.svg',    'sessions_completed', 25),
(4, 'Master Mentor',      'Completed 40 mentoring sessions',          'badges/master.svg',         'sessions_completed', 40),
(5, 'Top Rated',          'Maintained an average rating of 4.8+',     'badges/top_rated.svg',      'avg_rating',         48),
(6, 'Community Star',     'Awarded by admin for community impact',    'badges/community.svg',      'community',          NULL),
(7, 'Excellent Feedback', 'Received 20+ feedback with rating 5',      'badges/excellent.svg',      'manual',             NULL);

-- ─── Profile (mentor/mentee profile details + avatar) ─────────────────────────
-- Previously created ad hoc by test_db.php / create_profile_table.php.

CREATE TABLE IF NOT EXISTS `profile` (
    `profile_id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED NOT NULL,
    `full_name`     VARCHAR(120) NOT NULL,
    `student_id`    VARCHAR(50)  NOT NULL,
    `course`        VARCHAR(120) NOT NULL,
    `year_level`    VARCHAR(30)  NOT NULL,
    `club`          VARCHAR(100) NULL,
    `profile_image` VARCHAR(255) NULL,
    `updated_at`    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`profile_id`),
    UNIQUE KEY `uk_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Messages (direct messaging between mentor/mentee) ────────────────────────
-- Previously created ad hoc via inline CREATE TABLE IF NOT EXISTS on every
-- page load in App/views/Messages/*.php. Folded in here so a fresh deploy
-- running only this migration file has the table from the start.

CREATE TABLE IF NOT EXISTS `messages` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sender_id`   INT UNSIGNED NOT NULL,
    `receiver_id` INT UNSIGNED NOT NULL,
    `content`     TEXT         NOT NULL,
    `is_read`     TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sender` (`sender_id`),
    KEY `idx_receiver` (`receiver_id`),
    KEY `idx_pair_time` (`sender_id`, `receiver_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Notification preferences (Settings → Notifications) ──────────────────────
-- Previously localStorage-only (per-browser, not persisted). One row per user;
-- missing row = defaults below (matches the settings.php UI's prior hardcoded
-- defaults exactly, so existing users see no change until they touch a toggle).

CREATE TABLE IF NOT EXISTS `notification_preferences` (
    `user_id`           INT UNSIGNED NOT NULL,
    `session_requests`  TINYINT(1)   NOT NULL DEFAULT 1,
    `session_reminders` TINYINT(1)   NOT NULL DEFAULT 1,
    `feedback_received` TINYINT(1)   NOT NULL DEFAULT 1,
    `messages`          TINYINT(1)   NOT NULL DEFAULT 0,
    `updated_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Email delivery tracking on notifications ──────────────────────────────
-- Added alongside EmailService (App/services/EmailService.php) so each
-- in-app notification records whether its companion email went out.
-- 'skipped' = SMTP not configured in .env; 'pending' never persists past the
-- request that created the row (NotificationService::send() updates it
-- synchronously right after the insert).

ALTER TABLE `notifications`
    ADD COLUMN `email_status` ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending' AFTER `is_read`,
    ADD COLUMN `email_error`  TEXT NULL AFTER `email_status`;

-- ─── Mentee dashboard redesign: Goals, Resources, Events ───────────────────
-- Added to back the reference-design Mentee UI. None of these existed before
-- (confirmed via SHOW TABLES) — session_requests/mentee_preferences are
-- booking- and matching-focused, not goal-tracking or content-library tables.

CREATE TABLE IF NOT EXISTS `goals` (
    `goal_id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `mentee_id`    INT UNSIGNED NOT NULL,
    `mentor_id`    INT UNSIGNED NULL,              -- NULL = mentee self-created
    `title`        VARCHAR(150) NOT NULL,
    `description`  TEXT NULL,
    `status`       ENUM('not_started','in_progress','completed') NOT NULL DEFAULT 'not_started',
    `created_by`   INT UNSIGNED NOT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME NULL,
    PRIMARY KEY (`goal_id`),
    KEY `idx_mentee` (`mentee_id`),
    KEY `idx_mentor` (`mentor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Resources: shared study material (reviewers, handouts) uploaded by mentees
-- and mentors alike, filed under one of the 9 clubs the signup/verification
-- form already uses. Files are PDF or DOCX only — validated by extension AND
-- MIME on upload — and are served through the download route so every fetch
-- can be counted, never linked to directly.
--
-- NOTE: this replaces an earlier draft of this table (category / resource_type
-- / url / read_time_minutes) that was built for a generic article-and-video
-- library. It was still empty when it was rebuilt, so no data was migrated.
CREATE TABLE IF NOT EXISTS `resources` (
    `resource_id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uploader_id`    INT UNSIGNED NOT NULL,          -- who shared it (mentee or mentor)
    `club`           VARCHAR(100) NOT NULL,          -- which club it belongs to
    `title`          VARCHAR(200) NOT NULL,
    `description`    TEXT NULL,
    `file_path`      VARCHAR(255) NOT NULL,          -- stored name under public/uploads/resources/
    `original_name`  VARCHAR(255) NOT NULL,          -- name to send back on download
    `file_type`      ENUM('pdf','docx') NOT NULL,
    `file_size`      INT UNSIGNED NOT NULL DEFAULT 0,
    `download_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `is_active`      TINYINT(1) NOT NULL DEFAULT 1,  -- 0 = removed by its uploader
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`resource_id`),
    KEY `idx_uploader` (`uploader_id`),
    KEY `idx_club` (`club`),
    KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `resource_bookmarks` (
    `user_id`      INT UNSIGNED NOT NULL,
    `resource_id`  INT UNSIGNED NOT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `resource_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Events: admin-managed only (created_by is always an admin user_id);
-- everyone else browses/registers.
CREATE TABLE IF NOT EXISTS `events` (
    `event_id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`        VARCHAR(200) NOT NULL,
    `description`  TEXT NULL,
    `category`     VARCHAR(60) NOT NULL DEFAULT 'General',
    `event_date`   DATE NOT NULL,
    `start_time`   TIME NOT NULL,
    `end_time`     TIME NOT NULL,
    `location`     VARCHAR(200) NOT NULL DEFAULT 'Online',
    `is_online`    TINYINT(1) NOT NULL DEFAULT 1,
    `image_path`   VARCHAR(500) NULL,
    `created_by`   INT UNSIGNED NOT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`event_id`),
    KEY `idx_date` (`event_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `event_registrations` (
    `event_id`      INT UNSIGNED NOT NULL,
    `user_id`       INT UNSIGNED NOT NULL,
    `registered_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`event_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Assessments ──────────────────────────────────────────────────────────────
-- A mentor writes an assessment (title, topic, instructions, optional time
-- limit) made of questions; their mentees take it once and get scored.
-- "Their mentees" = anyone with an approved/completed session_request with
-- that mentor, so it reuses the relationship the app already tracks instead
-- of adding a separate assignment table.

CREATE TABLE IF NOT EXISTS `assessments` (
    `assessment_id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `mentor_id`          INT UNSIGNED NOT NULL,
    `title`              VARCHAR(200) NOT NULL,
    `topic`              VARCHAR(120) NOT NULL,
    `instructions`       TEXT NULL,
    `time_limit_minutes` INT UNSIGNED NULL,   -- NULL = untimed
    `status`             ENUM('draft','published') NOT NULL DEFAULT 'draft',
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `published_at`       DATETIME NULL,
    PRIMARY KEY (`assessment_id`),
    KEY `idx_mentor` (`mentor_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `assessment_questions` (
    `question_id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `assessment_id`  INT UNSIGNED NOT NULL,
    `question_order` INT UNSIGNED NOT NULL DEFAULT 1,
    `question_type`  ENUM('multiple_choice','true_false','short_answer') NOT NULL DEFAULT 'multiple_choice',
    `question_text`  TEXT NOT NULL,
    `hint`           VARCHAR(300) NULL,       -- shown to the mentee while answering
    `points`         INT UNSIGNED NOT NULL DEFAULT 1,
    `is_required`    TINYINT(1) NOT NULL DEFAULT 1,
    `correct_text`   VARCHAR(300) NULL,       -- short_answer only; choices live in assessment_options
    PRIMARY KEY (`question_id`),
    KEY `idx_assessment` (`assessment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `assessment_options` (
    `option_id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `question_id`  INT UNSIGNED NOT NULL,
    `option_order` INT UNSIGNED NOT NULL DEFAULT 1,
    `option_text`  VARCHAR(400) NOT NULL,
    `is_correct`   TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`option_id`),
    KEY `idx_question` (`question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One attempt per mentee per assessment (the unique key enforces it).
CREATE TABLE IF NOT EXISTS `assessment_attempts` (
    `attempt_id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `assessment_id` INT UNSIGNED NOT NULL,
    `mentee_id`     INT UNSIGNED NOT NULL,
    `started_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `submitted_at`  DATETIME NULL,
    `score`         INT UNSIGNED NOT NULL DEFAULT 0,
    `total_points`  INT UNSIGNED NOT NULL DEFAULT 0,
    `status`        ENUM('in_progress','submitted') NOT NULL DEFAULT 'in_progress',
    PRIMARY KEY (`attempt_id`),
    UNIQUE KEY `uniq_attempt` (`assessment_id`, `mentee_id`),
    KEY `idx_mentee` (`mentee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Answers are saved as the mentee goes, so a refresh or timeout keeps progress.
CREATE TABLE IF NOT EXISTS `assessment_answers` (
    `answer_id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `attempt_id`         INT UNSIGNED NOT NULL,
    `question_id`        INT UNSIGNED NOT NULL,
    `selected_option_id` INT UNSIGNED NULL,
    `answer_text`        VARCHAR(500) NULL,
    `is_correct`         TINYINT(1) NOT NULL DEFAULT 0,
    `points_earned`      INT UNSIGNED NOT NULL DEFAULT 0,
    `is_flagged`         TINYINT(1) NOT NULL DEFAULT 0,
    `answered_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`answer_id`),
    UNIQUE KEY `uniq_answer` (`attempt_id`, `question_id`),
    KEY `idx_attempt` (`attempt_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ═══════════════════════════════════════════════════════════════════════
-- Feedback — "Rate & Review" (mentee↔mentor), replaces the old Journal page
-- ═══════════════════════════════════════════════════════════════════════

-- The five aspects a mentee rates keep the columns feedback has always had
-- (communication / efficiency / knowledge / skill + rating); only the labels
-- shown in the UI changed, so historic ratings still mean what they meant:
--   communication -> Communication
--   efficiency    -> Interaction & Engagement
--   knowledge     -> Knowledge & Expertise
--   skill         -> Guidance & Support
--   rating        -> Overall Experience
-- What is new is a short optional note per aspect.
ALTER TABLE `feedback`
    ADD COLUMN `note_communication` VARCHAR(250) NULL AFTER `skill`,
    ADD COLUMN `note_efficiency`    VARCHAR(250) NULL AFTER `note_communication`,
    ADD COLUMN `note_knowledge`     VARCHAR(250) NULL AFTER `note_efficiency`,
    ADD COLUMN `note_skill`         VARCHAR(250) NULL AFTER `note_knowledge`,
    ADD COLUMN `note_overall`       VARCHAR(250) NULL AFTER `note_skill`;

-- The other direction: a mentor reviewing the mentee they just sat with.
-- Kept in its own table so mentor averages, MentorScoreService and the
-- mentor_feedback_summary view keep reading exactly what they always read.
CREATE TABLE IF NOT EXISTS `mentee_reviews` (
    `review_id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id`         INT NOT NULL,             -- session_requests.request_id
    `mentor_id`          INT NOT NULL,
    `mentee_id`          INT NOT NULL,
    `rating`             DECIMAL(2,1) NOT NULL DEFAULT 0.0,   -- Overall Experience
    `preparedness`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `participation`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `communication`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `receptiveness`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `note_preparedness`  VARCHAR(250) NULL,
    `note_participation` VARCHAR(250) NULL,
    `note_communication` VARCHAR(250) NULL,
    `note_receptiveness` VARCHAR(250) NULL,
    `note_overall`       VARCHAR(250) NULL,
    `comment`            TEXT NULL,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`review_id`),
    UNIQUE KEY `uniq_mentor_session` (`session_id`, `mentor_id`),
    KEY `idx_mentee` (`mentee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- "Save as Draft" lives here rather than in feedback/mentee_reviews, so an
-- unfinished review can never leak into anybody's published average.
CREATE TABLE IF NOT EXISTS `feedback_drafts` (
    `draft_id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `direction`  ENUM('mentee_to_mentor','mentor_to_mentee') NOT NULL,
    `session_id` INT NOT NULL,
    `author_id`  INT NOT NULL,
    `payload`    TEXT NOT NULL,                    -- JSON: the whole form
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`draft_id`),
    UNIQUE KEY `uniq_author_session` (`session_id`, `author_id`, `direction`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ═══════════════════════════════════════════════════════════════════════
-- Google Calendar link — approved sessions pushed to the real calendar
-- ═══════════════════════════════════════════════════════════════════════
-- One row per user who has connected their Google account. Events are given
-- a deterministic id ("pc<session>u<user>") so re-syncing updates rather than
-- duplicating, which means no separate event-mapping table is needed.
CREATE TABLE IF NOT EXISTS `google_calendar_links` (
    `link_id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`        INT NOT NULL,
    `google_email`   VARCHAR(190) NULL,          -- shown on the calendar card
    `calendar_id`    VARCHAR(190) NOT NULL DEFAULT 'primary',
    `access_token`   TEXT NULL,
    `refresh_token`  TEXT NULL,                  -- only issued on first consent
    `expires_at`     DATETIME NULL,
    `last_synced_at` DATETIME NULL,
    `last_error`     VARCHAR(255) NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`link_id`),
    UNIQUE KEY `uniq_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Setup outside the database (Google Cloud Console, on the project that owns
-- GOOGLE_CLIENT_ID in .env):
--   1. APIs & Services → Library → enable "Google Calendar API".
--   2. Credentials → the OAuth 2.0 Client ID → Authorised redirect URIs →
--      add  http://localhost/case/case/gcal-callback.php
--      (and the same path on the production host when deployed).
--   3. OAuth consent screen → if it is still in "Testing", add each Google
--      account that will connect under "Test users".
--
-- Note on Testing mode: while the OAuth consent screen is in "Testing" with an
-- External user type, Google expires refresh tokens after 7 days. The calendar
-- link will therefore need reconnecting weekly until the app is verified or
-- published. The UI already surfaces this as "Your Google Calendar connection
-- has expired. Please disconnect and connect again."


-- ═══════════════════════════════════════════════════════════════════════
-- Settings — Account / Notifications / Data Privacy / Security
-- ═══════════════════════════════════════════════════════════════════════
ALTER TABLE `users` ADD COLUMN `username` VARCHAR(30) NULL AFTER `email`;
CREATE UNIQUE INDEX `uniq_username` ON `users` (`username`);

-- profile.user_id had a non-unique index, which made a per-user upsert
-- impossible; there was already exactly one row per user, so this only adds
-- the guarantee the code now relies on.
ALTER TABLE `profile` DROP INDEX `user_id`, ADD UNIQUE KEY `uniq_user` (`user_id`);

ALTER TABLE `profile`
    ADD COLUMN `phone`      VARCHAR(30) NULL,
    ADD COLUMN `location`   VARCHAR(120) NULL,
    ADD COLUMN `birthdate`  DATE NULL,
    ADD COLUMN `bio`        VARCHAR(500) NULL,
    -- 'private' removes a mentor from Find a Mentor (see find_mentor.php)
    ADD COLUMN `visibility` ENUM('everyone','mentors','private') NOT NULL DEFAULT 'everyone';

-- Only switches that actually change behaviour elsewhere in the app:
--   personalized_recommendations -> menteepage/index.php ignores mentee_preferences when off
--   share_activity               -> leaderboard/index.php excludes the mentor when off
--   third_party_integrations     -> gcal_connect.php refuses, and turning it
--                                   off disconnects any existing Google link
CREATE TABLE IF NOT EXISTS `privacy_settings` (
    `user_id` INT UNSIGNED NOT NULL,
    `personalized_recommendations` TINYINT(1) NOT NULL DEFAULT 1,
    `share_activity`               TINYINT(1) NOT NULL DEFAULT 1,
    `third_party_integrations`     TINYINT(1) NOT NULL DEFAULT 1,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Security activity reuses the existing `logs` table (the sign-in flow already
-- writes to it); update_password.php now records 'password changed' there too,
-- which is what drives "last changed" and the Security Status checklist.


-- ═══════════════════════════════════════════════════════════════════════
-- Profile — section, plus the interests / skills / areas-to-learn tags
-- ═══════════════════════════════════════════════════════════════════════
ALTER TABLE `profile` ADD COLUMN `section` VARCHAR(50) NULL AFTER `year_level`;

-- One row per tag so they can be queried and matched on later. tag_type:
--   interest -> "My Interests"
--   skill    -> "Skills I'm Developing"
--   learn    -> "Areas I Want to Learn"
-- Role-agnostic: the first-login questionnaire will write to the same table.
CREATE TABLE IF NOT EXISTS `user_tags` (
    `tag_id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT NOT NULL,
    `tag_type`   ENUM('interest','skill','learn') NOT NULL,
    `tag`        VARCHAR(60) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`tag_id`),
    UNIQUE KEY `uniq_tag` (`user_id`,`tag_type`,`tag`),
    KEY `idx_user_type` (`user_id`,`tag_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ═══════════════════════════════════════════════════════════════════════
-- First-login onboarding questionnaire (mentor + mentee)
-- ═══════════════════════════════════════════════════════════════════════
-- Two separate stamps rather than one flag, because "finished the
-- questionnaire" and "chose to skip it" need different follow-ups: a skipper
-- still sees the "Finish your profile" prompt on their Profile page, but is
-- never redirected again on login.
ALTER TABLE `profile`
    ADD COLUMN `onboarded_at`          DATETIME NULL DEFAULT NULL AFTER `visibility`,
    ADD COLUMN `onboarding_skipped_at` DATETIME NULL DEFAULT NULL AFTER `onboarded_at`;

-- Existing accounts are intentionally NOT backfilled: anyone who has never
-- answered the questionnaire gets it once on their next login.

-- The onboarding catalog carries full degree-program names ("Bachelor of
-- Technology and Livelihood Education (BTLED), Major in Home Economics" is
-- 80 characters), which overflowed the original 60-char tag column.
ALTER TABLE `user_tags` MODIFY `tag` VARCHAR(120) NOT NULL;


-- ═══════════════════════════════════════════════════════════════════════
-- session_requests.status — add the 'missed' value the app already uses
-- ═══════════════════════════════════════════════════════════════════════
-- cron/detect_missed_sessions.php writes status='missed' and the admin
-- dashboard counts it, but the enum never contained it. MySQL is not in
-- strict mode here, so every missed session was silently stored as '' —
-- invisible to the mentee's Past list, the mentor's lists, and the admin's
-- "Sessions Missed" counter (which therefore always read 0).
ALTER TABLE `session_requests`
    MODIFY `status` ENUM('pending','approved','completed','rejected','cancelled','missed') DEFAULT 'pending';


-- ═══════════════════════════════════════════════════════════════════════
-- Referential integrity for session_requests and friends
-- ═══════════════════════════════════════════════════════════════════════
-- Most tables already had ON DELETE CASCADE foreign keys to users, but
-- session_requests never did, so three rows survived pointing at accounts
-- (39, 40) that had been hard-deleted during development. One of them was a
-- request against a live mentor that could never be actioned: their pending
-- badge counted it while the list — which inner-joins users — could not show
-- it. Deleted first, because a constraint cannot be added over violating rows.
DELETE FROM `session_requests` WHERE `request_id` IN (131, 132, 133);

-- InnoDB needs an index on the child column; the rest already had one.
CREATE INDEX `idx_mr_mentor` ON `mentee_reviews` (`mentor_id`);

-- ON DELETE CASCADE ON UPDATE RESTRICT matches every foreign key already in
-- this schema. Note that the app itself soft-deletes accounts
-- (settings/delete_account.php sets status='blocked'), so a cascade only ever
-- fires on a manual/administrative row deletion — which is exactly the case
-- that produced the orphans above.
ALTER TABLE `session_requests`
    ADD CONSTRAINT `fk_sr_mentee` FOREIGN KEY (`mentee_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE ON UPDATE RESTRICT,
    ADD CONSTRAINT `fk_sr_mentor` FOREIGN KEY (`mentor_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE ON UPDATE RESTRICT;

ALTER TABLE `user_tags`
    ADD CONSTRAINT `fk_ut_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE ON UPDATE RESTRICT;

ALTER TABLE `mentee_reviews`
    ADD CONSTRAINT `fk_mr_mentee`  FOREIGN KEY (`mentee_id`)  REFERENCES `users`(`user_id`)               ON DELETE CASCADE ON UPDATE RESTRICT,
    ADD CONSTRAINT `fk_mr_mentor`  FOREIGN KEY (`mentor_id`)  REFERENCES `users`(`user_id`)               ON DELETE CASCADE ON UPDATE RESTRICT,
    ADD CONSTRAINT `fk_mr_session` FOREIGN KEY (`session_id`) REFERENCES `session_requests`(`request_id`) ON DELETE CASCADE ON UPDATE RESTRICT;

ALTER TABLE `feedback_drafts`
    ADD CONSTRAINT `fk_fd_session` FOREIGN KEY (`session_id`) REFERENCES `session_requests`(`request_id`) ON DELETE CASCADE ON UPDATE RESTRICT;

ALTER TABLE `google_calendar_links`
    ADD CONSTRAINT `fk_gcl_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE ON UPDATE RESTRICT;

ALTER TABLE `assessment_attempts`
    ADD CONSTRAINT `fk_aa_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `assessments`(`assessment_id`) ON DELETE CASCADE ON UPDATE RESTRICT;


-- ── messages ─────────────────────────────────────────────────────────────
-- Held 7 rows (ids 1-7, April) between accounts 8/9/10/17/18 that had been
-- hard-deleted, so the Messages page carried threads whose participants no
-- longer existed. Removed generically rather than by id, so re-running this
-- against a database with different leftovers still leaves it consistent.
DELETE FROM `messages`
 WHERE `sender_id`   NOT IN (SELECT `user_id` FROM `users`)
    OR `receiver_id` NOT IN (SELECT `user_id` FROM `users`);

-- A foreign key requires identical column types on both sides, and these were
-- INT(10) UNSIGNED against users.user_id INT(11). Every stored id is a small
-- positive number, so the narrowing is lossless. idx_sender / idx_receiver
-- already exist, so InnoDB needs no new index.
ALTER TABLE `messages`
    MODIFY `sender_id`   INT(11) NOT NULL,
    MODIFY `receiver_id` INT(11) NOT NULL;

ALTER TABLE `messages`
    ADD CONSTRAINT `fk_msg_sender`   FOREIGN KEY (`sender_id`)   REFERENCES `users`(`user_id`) ON DELETE CASCADE ON UPDATE RESTRICT,
    ADD CONSTRAINT `fk_msg_receiver` FOREIGN KEY (`receiver_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE ON UPDATE RESTRICT;


-- ── Everything else ──────────────────────────────────────────────────────
-- The remaining references, none of which held an orphan row. Two groups:
-- columns declared INT(10) UNSIGNED that first have to match users.user_id
-- (INT(11)), and columns that already matched their parent and only needed
-- the constraint. mentor_feedback_summary is a VIEW, so it takes neither.
CREATE INDEX `idx_aa_question`   ON `assessment_answers`  (`question_id`);
CREATE INDEX `idx_rb_resource`   ON `resource_bookmarks`  (`resource_id`);
CREATE INDEX `idx_er_user`       ON `event_registrations` (`user_id`);
CREATE INDEX `idx_fd_author`     ON `feedback_drafts`     (`author_id`);
CREATE INDEX `idx_aans_option`   ON `assessment_answers`  (`selected_option_id`);

ALTER TABLE `notifications`            MODIFY `user_id`            INT(11) NOT NULL;
ALTER TABLE `mentor_scores`            MODIFY `mentor_id`          INT(11) NOT NULL;
ALTER TABLE `mentee_preferences`       MODIFY `mentee_id`          INT(11) NOT NULL;
ALTER TABLE `notification_preferences` MODIFY `user_id`            INT(11) NOT NULL;
ALTER TABLE `privacy_settings`         MODIFY `user_id`            INT(11) NOT NULL;
ALTER TABLE `goals`                    MODIFY `mentee_id`          INT(11) NOT NULL;
ALTER TABLE `assessments`              MODIFY `mentor_id`          INT(11) NOT NULL;
ALTER TABLE `assessment_attempts`      MODIFY `mentee_id`          INT(11) NOT NULL;
ALTER TABLE `user_badges`              MODIFY `user_id`            INT(11) NOT NULL;
ALTER TABLE `user_certificates`        MODIFY `user_id`            INT(11) NOT NULL;
ALTER TABLE `missed_session_logs`      MODIFY `session_id`         INT(11) NOT NULL;
ALTER TABLE `resource_bookmarks`       MODIFY `user_id`            INT(11) NOT NULL;
ALTER TABLE `event_registrations`      MODIFY `user_id`            INT(11) NOT NULL;
ALTER TABLE `resources`                MODIFY `uploader_id`        INT(11) NOT NULL;

ALTER TABLE `notifications`            ADD CONSTRAINT `fk_notif_user`    FOREIGN KEY (`user_id`)   REFERENCES `users`(`user_id`) ON DELETE CASCADE ON UPDATE RESTRICT;
ALTER TABLE `mentor_scores`            ADD CONSTRAINT `fk_ms_mentor`     FOREIGN KEY (`mentor_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE ON UPDATE RESTRICT;
ALTER TABLE `mentee_preferences`       ADD CONSTRAINT `fk_mp_mentee`     FOREIGN KEY (`mentee_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE ON UPDATE RESTRICT;
ALTER TABLE `notification_preferences` ADD CONSTRAINT `fk_np_user`       FOREIGN KEY (`user_id`)   REFERENCES `users`(`user_id`) ON DELETE CASCADE ON UPDATE RESTRICT;
ALTER TABLE `privacy_settings`         ADD CONSTRAINT `fk_ps_user`       FOREIGN KEY (`user_id`)   REFERENCES `users`(`user_id`) ON DELETE CASCADE ON UPDATE RESTRICT;
ALTER TABLE `goals`                    ADD CONSTRAINT `fk_goals_mentee`  FOREIGN KEY (`mentee_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE ON UPDATE RESTRICT;
ALTER TABLE `assessments`              ADD CONSTRAINT `fk_as_mentor`     FOREIGN KEY (`mentor_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE ON UPDATE RESTRICT;
ALTER TABLE `assessment_attempts`      ADD CONSTRAINT `fk_aa_mentee`     FOREIGN KEY (`mentee_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE ON UPDATE RESTRICT;
ALTER TABLE `user_badges`              ADD CONSTRAINT `fk_ub_user`       FOREIGN KEY (`user_id`)   REFERENCES `users`(`user_id`) ON DELETE CASCADE ON UPDATE RESTRICT;
ALTER TABLE `user_certificates`        ADD CONSTRAINT `fk_uc_user`       FOREIGN KEY (`user_id`)   REFERENCES `users`(`user_id`) ON DELETE CASCADE ON UPDATE RESTRICT;
ALTER TABLE `resource_bookmarks`       ADD CONSTRAINT `fk_rb_user`       FOREIGN KEY (`user_id`)   REFERENCES `users`(`user_id`) ON DELETE CASCADE ON UPDATE RESTRICT;
ALTER TABLE `event_registrations`      ADD CONSTRAINT `fk_er_user`       FOREIGN KEY (`user_id`)   REFERENCES `users`(`user_id`) ON DELETE CASCADE ON UPDATE RESTRICT;
ALTER TABLE `feedback_drafts`          ADD CONSTRAINT `fk_fd_author`     FOREIGN KEY (`author_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE ON UPDATE RESTRICT;
ALTER TABLE `resources`                ADD CONSTRAINT `fk_res_uploader`  FOREIGN KEY (`uploader_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE ON UPDATE RESTRICT;

ALTER TABLE `missed_session_logs`      ADD CONSTRAINT `fk_msl_session`   FOREIGN KEY (`session_id`)    REFERENCES `session_requests`(`request_id`)     ON DELETE CASCADE ON UPDATE RESTRICT;
ALTER TABLE `assessment_questions`     ADD CONSTRAINT `fk_aq_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `assessments`(`assessment_id`)       ON DELETE CASCADE ON UPDATE RESTRICT;
ALTER TABLE `assessment_options`       ADD CONSTRAINT `fk_ao_question`   FOREIGN KEY (`question_id`)   REFERENCES `assessment_questions`(`question_id`) ON DELETE CASCADE ON UPDATE RESTRICT;
ALTER TABLE `assessment_answers`       ADD CONSTRAINT `fk_aans_attempt`  FOREIGN KEY (`attempt_id`)    REFERENCES `assessment_attempts`(`attempt_id`)   ON DELETE CASCADE ON UPDATE RESTRICT;
ALTER TABLE `assessment_answers`       ADD CONSTRAINT `fk_aans_question` FOREIGN KEY (`question_id`)   REFERENCES `assessment_questions`(`question_id`) ON DELETE CASCADE ON UPDATE RESTRICT;
ALTER TABLE `user_badges`              ADD CONSTRAINT `fk_ub_badge`      FOREIGN KEY (`badge_id`)      REFERENCES `badges`(`badge_id`)                  ON DELETE CASCADE ON UPDATE RESTRICT;
ALTER TABLE `user_certificates`        ADD CONSTRAINT `fk_uc_template`   FOREIGN KEY (`template_id`)   REFERENCES `certificate_templates`(`template_id`) ON DELETE CASCADE ON UPDATE RESTRICT;
ALTER TABLE `resource_bookmarks`       ADD CONSTRAINT `fk_rb_resource`   FOREIGN KEY (`resource_id`)   REFERENCES `resources`(`resource_id`)            ON DELETE CASCADE ON UPDATE RESTRICT;
ALTER TABLE `event_registrations`      ADD CONSTRAINT `fk_er_event`      FOREIGN KEY (`event_id`)      REFERENCES `events`(`event_id`)                  ON DELETE CASCADE ON UPDATE RESTRICT;

-- SET NULL rather than CASCADE: if a mentor edits a published assessment and
-- removes one option, the mentee's attempt should survive with that choice
-- cleared, not be deleted along with it. The column is nullable, so this is
-- the only rule that preserves the attempt record.
ALTER TABLE `assessment_answers`
    ADD CONSTRAINT `fk_aans_option` FOREIGN KEY (`selected_option_id`) REFERENCES `assessment_options`(`option_id`) ON DELETE SET NULL ON UPDATE RESTRICT;

-- Two more nullable references, both SET NULL for the same reason: the child
-- row has to outlive the thing it points at.
--   goals.mentor_id     — a mentee keeps their goal if the mentor account goes
--   passwords.token_id  — cleaning up an expired reset token must never delete
--                         somebody's password row and lock them out
CREATE INDEX `idx_pw_token` ON `passwords` (`token_id`);

ALTER TABLE `goals`     MODIFY `mentor_id` INT(11) NULL DEFAULT NULL;
ALTER TABLE `goals`     ADD CONSTRAINT `fk_goals_mentor` FOREIGN KEY (`mentor_id`) REFERENCES `users`(`user_id`)   ON DELETE SET NULL ON UPDATE RESTRICT;
ALTER TABLE `passwords` ADD CONSTRAINT `fk_pw_token`     FOREIGN KEY (`token_id`)  REFERENCES `tokens`(`token_id`) ON DELETE SET NULL ON UPDATE RESTRICT;

-- Left alone deliberately:
--   mentor_feedback_summary  a VIEW, so it can hold neither index nor key.
--   profile.student_id, user_verifications.student_id,
--   google_calendar_links.calendar_id
--                            look like keys but are external identifiers
--                            (school student number, Google calendar id).


-- ═══════════════════════════════════════════════════════════════════════
-- Drop the unused session_history table
-- ═══════════════════════════════════════════════════════════════════════
-- Dead schema. It held 0 rows, no code ever wrote to it, and nothing read it:
-- the similarly-named App/views/mentorpage/session_history.php is a view file
-- that queries session_requests. Verified before dropping — no foreign keys in
-- either direction, no views depending on it, no triggers.
--
-- Session history is already carried by session_requests (status, completed_at
-- and missed_by), which is what every page actually reads.
--
-- The original definition, recorded here so the drop is reversible:
--
--   CREATE TABLE `session_history` (
--     `history_id`   int(11) NOT NULL AUTO_INCREMENT,
--     `session_id`   int(11) DEFAULT NULL,
--     `mentee_id`    int(11) DEFAULT NULL,
--     `mentor_id`    int(11) DEFAULT NULL,
--     `subject`      varchar(100) DEFAULT NULL,
--     `session_date` datetime DEFAULT NULL,
--     `status`       varchar(20) DEFAULT NULL,
--     `completed_at` datetime DEFAULT current_timestamp(),
--     PRIMARY KEY (`history_id`)
--   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `session_history`;


-- ═══════════════════════════════════════════════════════════════════════
-- "Remember me" on the login page
-- ═══════════════════════════════════════════════════════════════════════
-- The old login modal had a "Remember me" checkbox that nothing read — the
-- POST field was never looked at, so ticking it did nothing at all. The
-- rebuilt login page implements it properly with a split cookie:
--
--   selector   a public lookup key, stored as-is so the row can be found
--   validator  a secret, stored only as a SHA-256 hash and compared with
--              hash_equals(), so a leaked database still cannot be replayed
--
-- The pair travels in one cookie as "selector:validator". The token rotates
-- on every use, so a stolen cookie stops working as soon as the real user
-- returns. CASCADE means deleting an account drops its tokens with it.
CREATE TABLE IF NOT EXISTS `remember_tokens` (
    `token_id`   INT(11) NOT NULL AUTO_INCREMENT,
    `user_id`    INT(11) NOT NULL,
    `selector`   CHAR(32) NOT NULL,
    `validator`  CHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`token_id`),
    UNIQUE KEY `uniq_selector` (`selector`),
    KEY `idx_rt_user` (`user_id`),
    CONSTRAINT `fk_rt_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ═══════════════════════════════════════════════════════════════════════
-- "Forgot password" on the login page
-- ═══════════════════════════════════════════════════════════════════════
-- Same split-token shape as remember_tokens above, and for the same reason:
-- the selector is the public lookup key, the validator is a secret that is
-- only ever stored as a SHA-256 hash. Someone who can read this table still
-- cannot construct a working reset link.
--
-- Differences from remember_tokens:
--
--   used_at    rows are marked, never deleted, on use. Deleting would make
--              a replayed link indistinguishable from an expired one, and
--              would also destroy the history the request throttle counts.
--   expires_at one hour. Long enough to find the mail, short enough that an
--              old message in an inbox is not a standing key to the account.
--
-- Requesting a new link marks every earlier unused row for that account as
-- used, so only the most recent link ever works.
CREATE TABLE IF NOT EXISTS `password_resets` (
    `reset_id`   INT(11) NOT NULL AUTO_INCREMENT,
    `user_id`    INT(11) NOT NULL,
    `selector`   CHAR(32) NOT NULL,
    `validator`  CHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at`    DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`reset_id`),
    UNIQUE KEY `uniq_pr_selector` (`selector`),
    KEY `idx_pr_user` (`user_id`),
    KEY `idx_pr_expires` (`expires_at`),
    CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ═══════════════════════════════════════════════════════════════════════
-- Login tokens: stop the table growing without bound
-- ═══════════════════════════════════════════════════════════════════════
-- `tokens` had 110 rows against 13 accounts, every one expired and the oldest
-- five months old. Two leaks fed it:
--
--   1. login.php inserted a row and repointed passwords.token_id at it, which
--      orphaned whatever the previous login had left there. Nothing ever
--      collected the orphan.
--   2. logout.php was the only deleter, so a closed tab or an expired session
--      left its row behind permanently.
--
-- App/services/LoginTokenService.php now owns the lifecycle: issue() retires
-- the account's previous row before minting a new one, and both issue() and
-- revoke() sweep expired rows. This index is what makes that sweep cheap.
ALTER TABLE `tokens` ADD KEY `idx_tokens_expiration` (`expiration`);

-- One-off collection of the existing backlog. Only expired rows are touched,
-- so a live session cannot be logged out by this. The foreign key on
-- passwords.token_id is ON DELETE SET NULL, so the accounts still pointing at
-- long-dead tokens have those pointers cleared as a side effect.
DELETE FROM `tokens` WHERE `expiration` IS NULL OR `expiration` < NOW();


-- ═══════════════════════════════════════════════════════════════════════
-- Login tokens no longer expire
-- ═══════════════════════════════════════════════════════════════════════
-- Requested change: nothing should sign a user out on a timer. Two things did.
--
--   1. tokens.expiration gave every login a 30-minute life, and
--      session-check.php force-logged-out anything past it.
--   2. session.gc_maxlifetime was 1800, so PHP's collector was free to delete
--      the session file after 30 idle minutes. This was the one that actually
--      bit, since session-check.php is included by no page.
--
-- Both are gone. The lifetime now sits at 30 days in Framework/bootstrap.php,
-- and the expiry column is dropped here rather than left behind holding
-- timestamps nothing maintains.
--
-- created_at replaces it for garbage collection. Without an expiry, the only
-- collectable token is one no `passwords` row points at (left behind when an
-- account is deleted) — but issue() writes the row and sets that pointer as
-- two statements, so a brand-new token briefly looks orphaned. The sweep
-- ignores anything under 24 hours old, which closes that race.
--
-- NOTE: the session COOKIE is still a browser-session cookie. Closing the
-- browser signs you out unless "Remember me" was ticked; that checkbox is what
-- persists a login across restarts (App/services/RememberService.php).
-- Dropping the column drops idx_tokens_expiration with it — that index covered
-- only that column — so there is no separate DROP INDEX here. The new sweep
-- filters on created_at, which gets its own index.
ALTER TABLE `tokens`
    DROP COLUMN `expiration`,
    ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD KEY `idx_tokens_created` (`created_at`);
