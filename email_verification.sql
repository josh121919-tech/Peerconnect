-- email_verification.sql — adds the email-confirmation stage to signup.
--
-- Run this as root:
--     C:\xampp\mysql\bin\mysql -u root cs < email_verification.sql
--
-- Until it has been run the application still works: the code checks whether
-- users.email_verified_at exists and, when it does not, treats every account
-- as having confirmed its address (see EmailVerificationService::available()).
-- So the site never breaks in the window between deploying and migrating.
--
-- Two things change.
--
-- 1. users.email_verified_at — when the owner proved they can read the
--    address. NULL means they have not yet. It is a timestamp rather than a
--    flag because "when" answers support questions that "whether" cannot.
--
-- 2. email_verifications — one row per confirmation link, in the same shape
--    as password_resets: the link carries "selector:validator", only the
--    selector is stored in the clear, and the validator is kept as a SHA-256
--    hash. Read access to the database is therefore not enough to forge a
--    confirmation link.

ALTER TABLE users
  ADD COLUMN email_verified_at DATETIME NULL DEFAULT NULL AFTER verified;

-- Every account that already exists was created before this stage did, so it
-- is confirmed as of its own signup. Without this line all 20 live accounts
-- would be locked out of their own dashboards the moment the gate goes in.
UPDATE users SET email_verified_at = created_at WHERE email_verified_at IS NULL;

CREATE TABLE IF NOT EXISTS email_verifications (
  verification_id INT(11)     NOT NULL AUTO_INCREMENT,
  user_id         INT(11)     NOT NULL,
  selector        CHAR(32)    NOT NULL,
  validator       CHAR(64)    NOT NULL,
  expires_at      DATETIME    NOT NULL,
  used_at         DATETIME    NULL DEFAULT NULL,
  created_at      DATETIME    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (verification_id),
  UNIQUE KEY uniq_ev_selector (selector),
  KEY idx_ev_user (user_id),
  KEY idx_ev_expires (expires_at),
  CONSTRAINT fk_ev_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
