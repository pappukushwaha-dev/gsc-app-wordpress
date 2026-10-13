-- ============================================================
-- 09-settings-copy.sql   (one-shot import - NOT a migration)
--
-- Copies the shared settings and content data from the family
-- sandbox database into THIS WordPress database:
--
--   admin_settings            (merged: source keys, incl. the
--                              PageSpeed API key)
--   other_apps                (replaced - the Other Apps catalog)
--   faqs + faq_categories     (replaced)
--   email_templates +
--   email_categories          (replaced)
--   documentation +
--   documentation_categories  (replaced)
--   admin_users               (merged - your own admins stay)
--
-- SCHEMA DRIFT: the sandbox tables have evolved separately, so for
-- every table the copy inserts only the columns that exist on BOTH
-- sides (read from information_schema at run time). A column the
-- source does not have is simply not copied; the target's own
-- default fills it.
--
-- DELIBERATELY NOT copied:
--   google_settings     - this app's Google OAuth project is live;
--                         overwriting it breaks Connect Google
--   shopify_credentials /
--   gbigcomm_settings /
--   ecwid_settings      - other platforms' credentials, meaningless here
--   google_accounts,
--   other_app_clicks    - per-user runtime data, not settings
--
-- HOW TO RUN (phpMyAdmin) - exactly two things:
--   1. In the LEFT panel, SELECT THE WORDPRESS DATABASE
--      (wordpress-googlesearchconsole).
--   2. SQL tab -> paste this whole file -> Go.
--
-- A canary stops the script with an error when the selected DB is
-- not the WordPress one - it owns the WpSite table, the sandbox does
-- not. Without this gate a wrong-DB run would truncate sandbox
-- tables (the writes below are unqualified on purpose).
--
-- Safe to run more than once: content tables are truncated and
-- re-copied to the exact source state; settings and admins merge.
-- ============================================================ */

-- ---------- 0. safety: refuse to run anywhere but the WordPress DB ----------

SET @canary := (SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'WpSite');

SET @gate_sql := IF(@canary > 0,
    'SELECT ''OK - WordPress database detected, importing...'' AS canary_check',
    'SELECT * FROM `___WRONG_DATABASE___SELECT_WORDPRESS-GOOGLESEARCHCONSOLE_IN_THE_LEFT_PANEL_AND_RUN_AGAIN___`');
PREPARE gate_stmt FROM @gate_sql;
EXECUTE gate_stmt;
DEALLOCATE PREPARE gate_stmt;

-- ---------- helper block, repeated per table ----------
-- @cols = the columns present on BOTH sides, in target order.

-- ---------- 1. admin_settings: merge (source wins on shared keys) ----------

UPDATE admin_settings t
JOIN `google_search_console_sanbox`.admin_settings s ON s.key_name = t.key_name
SET t.value = s.value, t.updated_at = NOW();

INSERT INTO admin_settings (key_name, value)
SELECT s.key_name, s.value
FROM `google_search_console_sanbox`.admin_settings s
WHERE NOT EXISTS (SELECT 1 FROM admin_settings t WHERE t.key_name = s.key_name);

-- ---------- 2. content tables: replace ----------
-- The schema carries FKs (email_templates -> email_categories and
-- friends), and MySQL refuses TRUNCATE on a referenced parent even
-- when the child is empty. Checks go off for the reseed only.

SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE other_apps;

SET @cols := (SELECT GROUP_CONCAT(c.COLUMN_NAME ORDER BY c.ORDINAL_POSITION SEPARATOR ',')
  FROM information_schema.COLUMNS c
  WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = 'other_apps'
    AND EXISTS (SELECT 1 FROM information_schema.COLUMNS s
                WHERE s.TABLE_SCHEMA = 'google_search_console_sanbox'
                  AND s.TABLE_NAME = 'other_apps' AND s.COLUMN_NAME = c.COLUMN_NAME));
SET @sql := IF(@cols IS NULL,
    'SELECT ''other_apps: no common columns - skipped'' AS note',
    CONCAT('INSERT INTO other_apps (', @cols, ') SELECT ', @cols,
           ' FROM `google_search_console_sanbox`.other_apps'));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

TRUNCATE TABLE faqs;
TRUNCATE TABLE faq_categories;

SET @cols := (SELECT GROUP_CONCAT(c.COLUMN_NAME ORDER BY c.ORDINAL_POSITION SEPARATOR ',')
  FROM information_schema.COLUMNS c
  WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = 'faq_categories'
    AND EXISTS (SELECT 1 FROM information_schema.COLUMNS s
                WHERE s.TABLE_SCHEMA = 'google_search_console_sanbox'
                  AND s.TABLE_NAME = 'faq_categories' AND s.COLUMN_NAME = c.COLUMN_NAME));
SET @sql := IF(@cols IS NULL,
    'SELECT ''faq_categories: no common columns - skipped'' AS note',
    CONCAT('INSERT INTO faq_categories (', @cols, ') SELECT ', @cols,
           ' FROM `google_search_console_sanbox`.faq_categories'));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @cols := (SELECT GROUP_CONCAT(c.COLUMN_NAME ORDER BY c.ORDINAL_POSITION SEPARATOR ',')
  FROM information_schema.COLUMNS c
  WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = 'faqs'
    AND EXISTS (SELECT 1 FROM information_schema.COLUMNS s
                WHERE s.TABLE_SCHEMA = 'google_search_console_sanbox'
                  AND s.TABLE_NAME = 'faqs' AND s.COLUMN_NAME = c.COLUMN_NAME));
SET @sql := IF(@cols IS NULL,
    'SELECT ''faqs: no common columns - skipped'' AS note',
    CONCAT('INSERT INTO faqs (', @cols, ') SELECT ', @cols,
           ' FROM `google_search_console_sanbox`.faqs'));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

TRUNCATE TABLE email_templates;
TRUNCATE TABLE email_categories;

SET @cols := (SELECT GROUP_CONCAT(c.COLUMN_NAME ORDER BY c.ORDINAL_POSITION SEPARATOR ',')
  FROM information_schema.COLUMNS c
  WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = 'email_categories'
    AND EXISTS (SELECT 1 FROM information_schema.COLUMNS s
                WHERE s.TABLE_SCHEMA = 'google_search_console_sanbox'
                  AND s.TABLE_NAME = 'email_categories' AND s.COLUMN_NAME = c.COLUMN_NAME));
SET @sql := IF(@cols IS NULL,
    'SELECT ''email_categories: no common columns - skipped'' AS note',
    CONCAT('INSERT INTO email_categories (', @cols, ') SELECT ', @cols,
           ' FROM `google_search_console_sanbox`.email_categories'));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @cols := (SELECT GROUP_CONCAT(c.COLUMN_NAME ORDER BY c.ORDINAL_POSITION SEPARATOR ',')
  FROM information_schema.COLUMNS c
  WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = 'email_templates'
    AND EXISTS (SELECT 1 FROM information_schema.COLUMNS s
                WHERE s.TABLE_SCHEMA = 'google_search_console_sanbox'
                  AND s.TABLE_NAME = 'email_templates' AND s.COLUMN_NAME = c.COLUMN_NAME));
SET @sql := IF(@cols IS NULL,
    'SELECT ''email_templates: no common columns - skipped'' AS note',
    CONCAT('INSERT INTO email_templates (', @cols, ') SELECT ', @cols,
           ' FROM `google_search_console_sanbox`.email_templates'));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

TRUNCATE TABLE documentation;
TRUNCATE TABLE documentation_categories;

SET @cols := (SELECT GROUP_CONCAT(c.COLUMN_NAME ORDER BY c.ORDINAL_POSITION SEPARATOR ',')
  FROM information_schema.COLUMNS c
  WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = 'documentation_categories'
    AND EXISTS (SELECT 1 FROM information_schema.COLUMNS s
                WHERE s.TABLE_SCHEMA = 'google_search_console_sanbox'
                  AND s.TABLE_NAME = 'documentation_categories' AND s.COLUMN_NAME = c.COLUMN_NAME));
SET @sql := IF(@cols IS NULL,
    'SELECT ''documentation_categories: no common columns - skipped'' AS note',
    CONCAT('INSERT INTO documentation_categories (', @cols, ') SELECT ', @cols,
           ' FROM `google_search_console_sanbox`.documentation_categories'));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @cols := (SELECT GROUP_CONCAT(c.COLUMN_NAME ORDER BY c.ORDINAL_POSITION SEPARATOR ',')
  FROM information_schema.COLUMNS c
  WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = 'documentation'
    AND EXISTS (SELECT 1 FROM information_schema.COLUMNS s
                WHERE s.TABLE_SCHEMA = 'google_search_console_sanbox'
                  AND s.TABLE_NAME = 'documentation' AND s.COLUMN_NAME = c.COLUMN_NAME));
SET @sql := IF(@cols IS NULL,
    'SELECT ''documentation: no common columns - skipped'' AS note',
    CONCAT('INSERT INTO documentation (', @cols, ') SELECT ', @cols,
           ' FROM `google_search_console_sanbox`.documentation'));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------- 3. admin_users: merge, keep this app's own logins ----------

SET @cols := (SELECT GROUP_CONCAT(c.COLUMN_NAME ORDER BY c.ORDINAL_POSITION SEPARATOR ',')
  FROM information_schema.COLUMNS c
  WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = 'admin_users'
    AND c.COLUMN_NAME IN ('id', 'username', 'email', 'password', 'role', 'status')
    AND EXISTS (SELECT 1 FROM information_schema.COLUMNS s
                WHERE s.TABLE_SCHEMA = 'google_search_console_sanbox'
                  AND s.TABLE_NAME = 'admin_users' AND s.COLUMN_NAME = c.COLUMN_NAME));
SET @sql := IF(@cols IS NULL,
    'SELECT ''admin_users: no common columns - skipped'' AS note',
    CONCAT('INSERT IGNORE INTO admin_users (', @cols, ') SELECT ', @cols,
           ' FROM `google_search_console_sanbox`.admin_users'));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- 4. bump auto-increment past the copied ids ----------

SET @mx := (SELECT COALESCE(MAX(id), 0) + 1 FROM other_apps);
SET @s := CONCAT('ALTER TABLE other_apps AUTO_INCREMENT = ', @mx);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @mx := (SELECT COALESCE(MAX(id), 0) + 1 FROM faqs);
SET @s := CONCAT('ALTER TABLE faqs AUTO_INCREMENT = ', @mx);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @mx := (SELECT COALESCE(MAX(id), 0) + 1 FROM faq_categories);
SET @s := CONCAT('ALTER TABLE faq_categories AUTO_INCREMENT = ', @mx);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @mx := (SELECT COALESCE(MAX(id), 0) + 1 FROM email_templates);
SET @s := CONCAT('ALTER TABLE email_templates AUTO_INCREMENT = ', @mx);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @mx := (SELECT COALESCE(MAX(id), 0) + 1 FROM email_categories);
SET @s := CONCAT('ALTER TABLE email_categories AUTO_INCREMENT = ', @mx);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @mx := (SELECT COALESCE(MAX(id), 0) + 1 FROM documentation);
SET @s := CONCAT('ALTER TABLE documentation AUTO_INCREMENT = ', @mx);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @mx := (SELECT COALESCE(MAX(id), 0) + 1 FROM documentation_categories);
SET @s := CONCAT('ALTER TABLE documentation_categories AUTO_INCREMENT = ', @mx);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- 5. verify ----------

SELECT 'admin_settings' AS what, COUNT(*) AS rows_now FROM admin_settings
UNION ALL SELECT 'other_apps',      COUNT(*) FROM other_apps
UNION ALL SELECT 'faqs',            COUNT(*) FROM faqs
UNION ALL SELECT 'faq_categories',  COUNT(*) FROM faq_categories
UNION ALL SELECT 'email_templates', COUNT(*) FROM email_templates
UNION ALL SELECT 'email_categories',COUNT(*) FROM email_categories
UNION ALL SELECT 'documentation',   COUNT(*) FROM documentation
UNION ALL SELECT 'doc_categories',  COUNT(*) FROM documentation_categories
UNION ALL SELECT 'admin_users',     COUNT(*) FROM admin_users;

SELECT key_name FROM admin_settings WHERE key_name = 'pagespeed_api_key';
