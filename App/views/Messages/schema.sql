-- ─────────────────────────────────────────────────────────
-- NEUST Messaging System — Database Schema
-- Run this once in your MySQL / phpMyAdmin
-- ─────────────────────────────────────────────────────────

-- 1. Messages table
CREATE TABLE IF NOT EXISTS `messages` (
  `id`          INT          UNSIGNED NOT NULL AUTO_INCREMENT,
  `sender_id`   INT          UNSIGNED NOT NULL,
  `receiver_id` INT          UNSIGNED NOT NULL,
  `content`     TEXT         NOT NULL,
  `is_read`     TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sender`   (`sender_id`),
  KEY `idx_receiver` (`receiver_id`),
  KEY `idx_convo`    (`sender_id`, `receiver_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 2. Users table (if you don't have one yet)
CREATE TABLE IF NOT EXISTS `users` (
  `id`         INT          UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(120) NOT NULL,
  `email`      VARCHAR(180) NOT NULL UNIQUE,
  `password`   VARCHAR(255) NOT NULL,
  `role`       ENUM('mentor','mentee') NOT NULL DEFAULT 'mentee',
  `avatar`     VARCHAR(255)            DEFAULT NULL,
  `created_at` DATETIME     NOT NULL   DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 3. Sample data (optional — remove in production)
INSERT IGNORE INTO `users` (`id`, `name`, `email`, `password`, `role`) VALUES
(1, 'Mentor User',        'mentor@neust.edu',   'hashed_pw', 'mentor'),
(2, 'Miko Domingo',       'miko@neust.edu',      'hashed_pw', 'mentee'),
(3, 'Hazel Kim Maglanoc', 'hazel@neust.edu',     'hashed_pw', 'mentee'),
(4, 'Areown Dapne Venus', 'areown@neust.edu',    'hashed_pw', 'mentee'),
(5, 'Josh Emil Palad',    'josh@neust.edu',      'hashed_pw', 'mentee');

INSERT IGNORE INTO `messages` (`sender_id`, `receiver_id`, `content`, `is_read`) VALUES
(1, 2, 'Mr. Domingo, your session will be at 01:30 pm. Thank you', 1),
(2, 1, 'Yes Sir, Thank you po.',                                   1),
(2, 1, 'Saan na nga po uli ''yong venue?',                         0);
