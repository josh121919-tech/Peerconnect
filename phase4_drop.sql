-- phase4_drop.sql — removes the four unused database objects (audit D1, D2, D3,
-- and the categories table retired on 2026-09-14).
--
-- Verified before writing this file:
--   events                  0 rows, no PHP file references it
--   event_registrations     0 rows, no PHP file references it
--   mentor_feedback_summary a VIEW over feedback, never queried by any file
--   categories              15 rows, read only by admin/categories.php, which
--                           was removed with its route; subjects members see
--                           come from App/config/onboarding_catalog.php
--
-- No trigger, stored routine, view or foreign key depends on any of them.
-- event_registrations holds a foreign key to events, so the child drops first.
--
-- A full restorable backup was taken beforehand:
--   scratchpad/phase4_bak/cs_full_before.sql   (whole schema + data)
--   scratchpad/phase4_bak/ddl_events.sql       (both tables, with constraints)
--   scratchpad/phase4_bak/ddl_view.sql         (the view definition)
--   C:\PeerConnectBackups\categories_table_before_removal_2026-09-14.sql
--                                              (categories, schema + 15 rows)
--
-- Run with:  mysql -uroot cs < phase4_drop.sql
--
-- RUN on 2026-09-14: the database now has 43 tables and no views, and
-- database_migrations.sql was regenerated to match. Kept as the record of what
-- was dropped and where the backups are.

DROP TABLE IF EXISTS `event_registrations`;
DROP TABLE IF EXISTS `events`;
DROP VIEW  IF EXISTS `mentor_feedback_summary`;
DROP TABLE IF EXISTS `categories`;

-- Expect: BASE TABLE 43, VIEW 0
SELECT TABLE_TYPE, COUNT(*) AS n
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'cs'
GROUP BY TABLE_TYPE;
