-- assessment_audience.sql — let a mentor choose which of their mentees an
-- assessment is for, instead of it going to all of them.
--
-- Run this as root:
--     C:/xampp/mysql/bin/mysql.exe -u root cs -e "source C:/xampp/htdocs/case/case/assessment_audience.sql"
--
-- Until it has been run the application still works: the code checks whether
-- this table exists and, when it does not, hides the picker and keeps the old
-- behaviour — every mentee the mentor has had a session with. So the site
-- never breaks in the window between deploying and migrating.
--
-- No rows here for an assessment means "all my mentees", which is what every
-- assessment written before today meant. That is why there is no backfill:
-- an empty table is already the correct answer for all of them.
--
-- assessment_id is INT(10) UNSIGNED and mentee_id is INT(11) because that is
-- what assessments.assessment_id and users.user_id are; a foreign key whose
-- type does not match exactly is refused.

CREATE TABLE IF NOT EXISTS assessment_mentees (
  assessment_id INT(10) UNSIGNED NOT NULL,
  mentee_id     INT(11)          NOT NULL,
  added_at      DATETIME         NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (assessment_id, mentee_id),
  KEY idx_am_mentee (mentee_id),
  CONSTRAINT fk_am_assessment FOREIGN KEY (assessment_id)
      REFERENCES assessments (assessment_id) ON DELETE CASCADE,
  CONSTRAINT fk_am_mentee FOREIGN KEY (mentee_id)
      REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
