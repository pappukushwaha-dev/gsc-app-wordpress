-- ============================================================
-- 02-review-prompts.sql   (HighLevel)
--
-- Tracks which review-popup trigger has already been SHOWN for a store,
-- so every trigger fires at most once per store (until the user actually
-- submits a review - submission is tracked on app_reviews.submitted).
--
-- Triggers: plan_purchased | first_report | stats_improved |
--           sitemap_submitted | action_center
--
-- Safe to run more than once.
-- ============================================================ */

SET @s := IF((SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'review_prompts') = 0,
  'CREATE TABLE review_prompts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    instance_id  VARCHAR(191) NOT NULL DEFAULT '''',
    trigger_name VARCHAR(40)  NOT NULL DEFAULT '''',
    shown_at     TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_trigger (instance_id, trigger_name),
    KEY idx_instance (instance_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT ''review_prompts already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
