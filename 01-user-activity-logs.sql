-- ============================================================
-- 01-user-activity-logs.sql   (HighLevel)
--
-- Tracks user activity inside this app:
--   action_type = 'login'      session started (once per session)
--   action_type = 'page_view'  every dashboard page render
--   action_type = 'logout'     explicit logout
--
-- One row per event. The admin views it in admin/activity-logs.php.
--
-- Safe to run more than once: the statement checks information_schema
-- first, so a table that is already present is skipped rather than
-- erroring.
--
-- Run it against this app's own database:
--   mysql -u USER -p <DB_NAME> < 01-user-activity-logs.sql
--
-- ============================================================ */

SET @s := IF((SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_activity_logs') = 0,
  'CREATE TABLE user_activity_logs (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    instance_id  VARCHAR(191) NOT NULL DEFAULT '''',
    email        VARCHAR(320) NOT NULL DEFAULT '''',
    action_type  VARCHAR(20)  NOT NULL DEFAULT ''page_view'',
    page_url     VARCHAR(500) NOT NULL DEFAULT '''',
    ip_address   VARCHAR(45)  NOT NULL DEFAULT '''',
    user_agent   VARCHAR(500) NOT NULL DEFAULT '''',
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_instance_time (instance_id, created_at),
    KEY idx_action_time (action_type, created_at),
    KEY idx_created_at (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT ''user_activity_logs already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
