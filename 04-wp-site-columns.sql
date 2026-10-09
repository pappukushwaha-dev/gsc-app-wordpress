-- ============================================================
-- 04-wp-site-columns.sql   (WordPress platform)
--
-- Upgrades an older WpSite table (from phase 1) to the full column
-- set the panel reads: display fields, secret hash, verification
-- token, sitemap and content-ping log columns.
--
-- Every ALTER is guarded - on a table that already has the column it
-- reports "already present" and moves on. Safe on fresh installs too.
--
-- Run after pulling the latest code:
--   mysql -u USER -p DB_NAME < 04-wp-site-columns.sql
-- ============================================================ */

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'WpSite' AND COLUMN_NAME = 'site_id') = 0,
  'ALTER TABLE WpSite ADD COLUMN site_id VARCHAR(191) DEFAULT NULL',
  'SELECT ''site_id already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'WpSite' AND COLUMN_NAME = 'shop_name') = 0,
  'ALTER TABLE WpSite ADD COLUMN shop_name VARCHAR(191) DEFAULT NULL',
  'SELECT ''shop_name already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'WpSite' AND COLUMN_NAME = 'shop_domain') = 0,
  'ALTER TABLE WpSite ADD COLUMN shop_domain VARCHAR(255) DEFAULT NULL',
  'SELECT ''shop_domain already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'WpSite' AND COLUMN_NAME = 'domain') = 0,
  'ALTER TABLE WpSite ADD COLUMN domain VARCHAR(255) DEFAULT NULL',
  'SELECT ''domain already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'WpSite' AND COLUMN_NAME = 'owner_email') = 0,
  'ALTER TABLE WpSite ADD COLUMN owner_email VARCHAR(320) DEFAULT NULL',
  'SELECT ''owner_email already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'WpSite' AND COLUMN_NAME = 'event_type') = 0,
  'ALTER TABLE WpSite ADD COLUMN event_type VARCHAR(50) DEFAULT NULL',
  'SELECT ''event_type already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'WpSite' AND COLUMN_NAME = 'lead_flag') = 0,
  'ALTER TABLE WpSite ADD COLUMN lead_flag TINYINT(1) DEFAULT 0',
  'SELECT ''lead_flag already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'WpSite' AND COLUMN_NAME = 'access_token') = 0,
  'ALTER TABLE WpSite ADD COLUMN access_token TEXT',
  'SELECT ''access_token already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'WpSite' AND COLUMN_NAME = 'secret_hash') = 0,
  'ALTER TABLE WpSite ADD COLUMN secret_hash CHAR(64) NOT NULL DEFAULT ''''',
  'SELECT ''secret_hash already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'WpSite' AND COLUMN_NAME = 'verify_token') = 0,
  'ALTER TABLE WpSite ADD COLUMN verify_token VARCHAR(255) NULL DEFAULT NULL',
  'SELECT ''verify_token already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'WpSite' AND COLUMN_NAME = 'sitemap_url') = 0,
  'ALTER TABLE WpSite ADD COLUMN sitemap_url VARCHAR(255) NULL DEFAULT NULL',
  'SELECT ''sitemap_url already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'WpSite' AND COLUMN_NAME = 'last_content_update') = 0,
  'ALTER TABLE WpSite ADD COLUMN last_content_update DATETIME NULL DEFAULT NULL',
  'SELECT ''last_content_update already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'WpSite' AND COLUMN_NAME = 'content_pings') = 0,
  'ALTER TABLE WpSite ADD COLUMN content_pings INT UNSIGNED NOT NULL DEFAULT 0',
  'SELECT ''content_pings already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

/* ------------------------------------------------------------
   Verify after running - every column should show:
------------------------------------------------------------ */

SELECT COLUMN_NAME FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'WpSite'
ORDER BY ORDINAL_POSITION;
