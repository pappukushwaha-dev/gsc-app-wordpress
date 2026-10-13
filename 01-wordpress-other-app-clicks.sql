-- ============================================================
-- 01-wordpress-other-app-clicks.sql   (WordPress)
--
-- Tracks every click a user makes from this app's Other Apps page
-- to one of the company's other apps.
--
-- One row per click: a user who clicks five times adds five rows, so
-- the table answers both "how many clicks did this app get" and
-- "which users clicked what, and when".
--
-- Safe to run more than once: the statement checks information_schema
-- first, so a table that is already present is skipped rather than
-- erroring. The guard is a PREPARE/EXECUTE pair, so it "runs" on
-- every pass - the test for a second run is that it reports the
-- table as present, not that it did nothing.
--
-- Run it against this app's own database:
--   mysql -u USER -p wordpress-googlesearchconsole < 01-wordpress-other-app-clicks.sql
--
-- ============================================================ */

SET @s := IF((SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'other_app_clicks') = 0,
  'CREATE TABLE other_app_clicks (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    instance_id    VARCHAR(191) NOT NULL DEFAULT '''',
    email          VARCHAR(320) NOT NULL DEFAULT '''',
    other_app_id   INT UNSIGNED NOT NULL DEFAULT 0,
    app_name       VARCHAR(191) NOT NULL DEFAULT '''',
    source_platform VARCHAR(20) NOT NULL DEFAULT ''wordpress'',
    clicked_at     TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_app_time (other_app_id, clicked_at),
    KEY idx_instance (instance_id),
    KEY idx_clicked_at (clicked_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT ''other_app_clicks already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

/* ------------------------------------------------------------ */

SELECT COUNT(*) AS clicks FROM other_app_clicks;
