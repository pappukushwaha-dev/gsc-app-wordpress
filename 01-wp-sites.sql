-- ============================================================
-- 01-wp-sites.sql   (WordPress platform)
--
-- The WpSite table: every WordPress site registered by the plugin,
-- one row per site. Idempotent on site_url - the register-site API
-- returns the same instance_id for the same site forever, so a
-- reinstalled plugin finds its history.
--
-- Safe to run more than once: guarded by an information_schema check.
--
-- Run against the WordPress platform database:
--   mysql -u USER -p <DB_NAME> < 01-wp-sites.sql
-- ============================================================ */

SET @s := IF((SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'WpSite') = 0,
  'CREATE TABLE WpSite (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    instance_id    VARCHAR(64) NOT NULL,
    site_url       VARCHAR(255) NOT NULL,
    wp_version     VARCHAR(20) DEFAULT NULL,
    plugin_version VARCHAR(20) DEFAULT NULL,
    email          VARCHAR(320) DEFAULT NULL,
    is_active      TINYINT(1) DEFAULT 1,
    last_seen      DATETIME DEFAULT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME DEFAULT NULL,
    digest_last_sent_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_instance (instance_id),
    UNIQUE KEY uniq_site_url (site_url),
    KEY idx_email (email)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT ''WpSite already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

/* ------------------------------------------------------------
   Check after running:
------------------------------------------------------------ */

SHOW TABLES LIKE 'WpSite';
