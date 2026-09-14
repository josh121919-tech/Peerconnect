-- restrict_app_db_user.sql — gives the app's MySQL account only what the app needs.
--
-- As created, `Peerconnect`@`%` has ALL PRIVILEGES ON *.* WITH GRANT OPTION:
-- every right on every database on this server (capstone, library, mysql and
-- the rest), from any computer that can reach port 3306, including the right to
-- create other accounts. MySQL here listens on all network interfaces, so that
-- is a second root account open to the network.
--
-- After this runs, the account:
--   * connects only from this computer (localhost), with the same password;
--     RENAME USER keeps it, so .env does not change
--   * can read, add, change and delete rows in `cs`, and nothing else
--   * has LOCK TABLES, SHOW VIEW, TRIGGER and EVENT on `cs`, which the nightly
--     backup (scripts/backup.php, mysqldump) needs
--   * cannot create, alter or drop tables, touch other databases, or grant
--
-- Schema changes (a future migration, a drop file) are then run as root, the
-- way phase4_drop.sql and drop_emails_table.sql were.
--
-- Run as root:  mysql -uroot < restrict_app_db_user.sql
-- If it reports "Operation RENAME USER failed", the account is already
-- @localhost: delete the RENAME line and run the file again.

RENAME USER 'Peerconnect'@'%' TO 'Peerconnect'@'localhost';

REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'Peerconnect'@'localhost';

GRANT SELECT, INSERT, UPDATE, DELETE, LOCK TABLES, SHOW VIEW, TRIGGER, EVENT
    ON `cs`.* TO 'Peerconnect'@'localhost';

-- Expect exactly two lines: USAGE on *.* (the login itself) and the grant on `cs`.*
SHOW GRANTS FOR 'Peerconnect'@'localhost';
