-- allow_rebooking.sql — let a mentee request a time again after it was
-- cancelled, declined, or they were removed from a group.
--
-- session_requests had a UNIQUE key on (mentor_id, mentee_id, session_date).
-- It also counted cancelled and declined rows, so asking for the same time
-- again crashed save_booking.php with "Duplicate entry". "One live request per
-- mentee per time" is enforced in save_booking.php instead, inside a lock, so
-- the old row — and the mentor's decline reason — stays in the history.
--
-- The key is replaced by an ordinary index on the same columns, so the lookups
-- that used it stay just as fast.
--
-- Run as root (the app's Peerconnect account cannot change tables), e.g. in
-- phpMyAdmin's SQL tab with the `cs` database selected, or:
--   C:\xampp\mysql\bin\mysql -u root cs < allow_rebooking.sql

ALTER TABLE session_requests
  DROP INDEX uniq_booking,
  ADD INDEX idx_booking (mentor_id, mentee_id, session_date);
