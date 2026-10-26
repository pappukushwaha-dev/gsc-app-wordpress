-- ============================================================
-- 03-app-reviews-submitted.sql
--
-- Ensures app_reviews.submitted exists. The review-popup gate
-- (api/check-review.php) and api/save-review.php both rely on it:
--   submitted = 0  -> review popup repeats on logins
--   submitted = 1  -> popup silenced permanently (user submitted)
--
-- Safe to run more than once.
-- ============================================================ */

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_reviews'
    AND COLUMN_NAME = 'submitted') = 0,
  'ALTER TABLE app_reviews ADD COLUMN submitted TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT ''submitted column already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;