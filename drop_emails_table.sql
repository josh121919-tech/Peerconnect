-- drop_emails_table.sql — retires the `emails` table (recommendation 6).
--
-- Every address now lives in users.email only. Verified before writing this file:
--   no PHP file reads or writes `emails` any more
--   no foreign key, trigger, view or stored routine refers to it
--   its 19 rows each match users.email exactly, letter case included
--   sign-in, Google sign-in, admin sign-in, password reset, sign-up duplicate
--   checks, change email and notification addresses were compared old query
--   against new for every account, and 39 admin and mentor pages showed the
--   same addresses and names before and after
--
-- Backup of the table (schema + 19 rows), taken first:
--   C:\PeerConnectBackups\emails_table_before_removal_2026-09-14.sql
--
-- Safety check: the table is dropped only if every row in it still matches
-- its account's users.email. Otherwise nothing is dropped and it says why.
--
-- Run with:  mysql -uroot cs < drop_emails_table.sql
--
-- RUN on 2026-09-14: the table is gone, the database has 42 tables, and
-- database_migrations.sql was regenerated to match. Kept as the record of what
-- was dropped and where the backup is.

SET @unmatched = (
    SELECT COUNT(*)
    FROM emails e
    LEFT JOIN users u ON u.user_id = e.user_id
    WHERE u.user_id IS NULL OR u.email IS NULL OR BINARY u.email <> BINARY e.email
);

SET @stmt = IF(@unmatched = 0,
    'DROP TABLE IF EXISTS `emails`',
    'SELECT CONCAT(@unmatched, '' row(s) in emails do not match users.email. Nothing was dropped.'') AS result');
PREPARE s FROM @stmt;
EXECUTE s;
DEALLOCATE PREPARE s;

-- Expect: emails_table_exists 0, BASE TABLE 42, VIEW 0 (no VIEW row)
SELECT COUNT(*) AS emails_table_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'cs' AND TABLE_NAME = 'emails';

SELECT TABLE_TYPE, COUNT(*) AS n
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'cs'
GROUP BY TABLE_TYPE;
