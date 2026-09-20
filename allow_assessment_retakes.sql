-- allow_assessment_retakes.sql — let a mentor reuse an assessment: a mentee
-- can take it again, and every attempt is kept as history.
--
-- assessment_attempts had a UNIQUE key on (assessment_id, mentee_id), so a
-- mentee could only ever hold one attempt of an assessment. Once they had
-- submitted, the assessment was spent for them: taking it again would have
-- crashed with "Duplicate entry", and the only way to reuse the questions was
-- to write the whole assessment out a second time.
--
-- The key is replaced by an ordinary index on the same columns, so the lookups
-- that used it stay just as fast, plus one for listing a mentee's attempts
-- newest first. "One attempt in progress at a time" is now enforced in the
-- code (App/services/AssessmentService.php) inside a lock, so a finished
-- attempt — its answers, its score, when it was taken — stays in the history.
--
-- Nothing is deleted and no existing attempt changes.
--
-- Run as root (the app's Peerconnect account cannot change tables), e.g. in
-- phpMyAdmin's SQL tab with the `cs` database selected, or:
--   C:\xampp\mysql\bin\mysql -u root cs < allow_assessment_retakes.sql

ALTER TABLE assessment_attempts
  DROP INDEX uniq_attempt,
  ADD INDEX idx_attempt_history (assessment_id, mentee_id, started_at);
