-- phase4_drop.sql — removes the three unused database objects (audit D1, D2, D3).
--
-- Verified before writing this file:
--   events                  0 rows, no PHP file references it
--   event_registrations     0 rows, no PHP file references it
--   mentor_feedback_summary a VIEW over feedback, never queried by any file
--
-- No trigger, stored routine, or other view depends on any of them.
-- event_registrations holds a foreign key to events, so the child drops first.
--
-- A full restorable backup was taken beforehand:
--   scratchpad/phase4_bak/cs_full_before.sql   (whole schema + data)
--   scratchpad/phase4_bak/ddl_events.sql       (both tables, with constraints)
--   scratchpad/phase4_bak/ddl_view.sql         (the view definition)
--
-- Run with:  mysql -uroot cs < phase4_drop.sql

DROP TABLE IF EXISTS `event_registrations`;
DROP TABLE IF EXISTS `events`;
DROP VIEW  IF EXISTS `mentor_feedback_summary`;

-- Expect: BASE TABLE 42, VIEW 0
SELECT TABLE_TYPE, COUNT(*) AS n
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'cs'
GROUP BY TABLE_TYPE;
