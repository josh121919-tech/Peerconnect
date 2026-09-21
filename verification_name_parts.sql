-- verification_name_parts.sql
--
-- Splits the applicant's name on a verification application into its parts,
-- so the form can ask for them separately and prefill them from the account.
--
-- Run as root:
--   C:\xampp\mysql\bin\mysql.exe -u root cs -e "source C:/xampp/htdocs/case/case/verification_name_parts.sql"
--
-- Widths match users.firstname / users.middlename / users.lastname exactly, so
-- a name that fits the account fits the application.
--
-- full_name stays, and the app keeps writing it. It is what the admin queue
-- and the notification text already read (AdminUserRepository), and existing
-- applications only have that one column — their parts are left NULL rather
-- than guessed at, because "Maria dela Cruz Santos" cannot be split back into
-- first/middle/last reliably and a wrong guess on an identity document is
-- worse than no guess. Anything submitted from now on fills all four.

ALTER TABLE `user_verifications`
    ADD COLUMN `firstname`  VARCHAR(50)  NULL DEFAULT NULL AFTER `full_name`,
    ADD COLUMN `middlename` VARCHAR(100) NULL DEFAULT NULL AFTER `firstname`,
    ADD COLUMN `lastname`   VARCHAR(50)  NULL DEFAULT NULL AFTER `middlename`;
