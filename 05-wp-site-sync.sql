-- ============================================================
-- 05-wp-site-sync.sql   (WordPress platform)
--
-- Adds the sync_secret column: a per-site secret the panel issues and
-- the plugin stores, used to authenticate the panel's instant-sync
-- call to the site's REST endpoint (wp-json/gscwp/v1/sync).
--
-- Distinct from the plugin's own registration secret. Safe to run
-- more than once (guarded).
-- ============================================================ */

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'WpSite' AND COLUMN_NAME = 'sync_secret') = 0,
  'ALTER TABLE WpSite ADD COLUMN sync_secret CHAR(32) NULL DEFAULT NULL',
  'SELECT ''sync_secret already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SELECT COLUMN_NAME FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'WpSite'
  AND COLUMN_NAME = 'sync_secret';
