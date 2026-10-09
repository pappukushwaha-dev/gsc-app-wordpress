-- ============================================================
-- 02-wp-site-meta.sql   (WordPress platform)
--
-- Adds what the plugin's on-site features need to WpSite:
--   secret_hash  - shared secret handed to the plugin at registration;
--                  authenticates every plugin->panel call (stored as
--                  hash, the secret itself never sits in the DB)
--   verify_token - the GSC verification token the plugin injects
--   sitemap_url  - the site's sitemap as reported by the plugin
--   last_content_update / content_pings - content ping log
--
-- Run AFTER 01-wp-sites.sql. Idempotent: every ALTER checks
-- information_schema first. Columns are appended without AFTER clauses
-- so the statements never depend on each other's order.
-- ============================================================ */

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'WpSite'
    AND COLUMN_NAME = 'secret_hash') = 0,
  'ALTER TABLE WpSite ADD COLUMN secret_hash CHAR(64) NOT NULL DEFAULT '''' AFTER instance_id',
  'SELECT ''WpSite.secret_hash already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'WpSite'
    AND COLUMN_NAME = 'verify_token') = 0,
  'ALTER TABLE WpSite ADD COLUMN verify_token VARCHAR(255) NULL DEFAULT NULL',
  'SELECT ''WpSite.verify_token already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'WpSite'
    AND COLUMN_NAME = 'sitemap_url') = 0,
  'ALTER TABLE WpSite ADD COLUMN sitemap_url VARCHAR(255) NULL DEFAULT NULL',
  'SELECT ''WpSite.sitemap_url already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'WpSite'
    AND COLUMN_NAME = 'last_content_update') = 0,
  'ALTER TABLE WpSite ADD COLUMN last_content_update DATETIME NULL DEFAULT NULL',
  'SELECT ''WpSite.last_content_update already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'WpSite'
    AND COLUMN_NAME = 'content_pings') = 0,
  'ALTER TABLE WpSite ADD COLUMN content_pings INT UNSIGNED NOT NULL DEFAULT 0',
  'SELECT ''WpSite.content_pings already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

/* ------------------------------------------------------------
   Existing rows (registered before this migration) get a secret on
   their next re-register from the plugin - the Connect button page
   offers Re-register, which refreshes it.
------------------------------------------------------------ */

SHOW COLUMNS FROM WpSite;
