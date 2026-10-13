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

INSERT INTO other_apps
    (id, app_name, is_index, app_price, title, tag_line, subtitle,
     description, image_url, button_text, button_link, status, sort_order)
SELECT id, app_name, is_index, app_price, title, tag_line, subtitle,
       description, image_url, button_text, button_link, status, sort_order
FROM `google_search_console_sanbox`.other_apps;

-- ---------- 3. faqs ----------

TRUNCATE TABLE faqs;
TRUNCATE TABLE faq_categories;

INSERT INTO faq_categories (id, name, sort_order)
SELECT id, name, sort_order
FROM `google_search_console_sanbox`.faq_categories;

INSERT INTO faqs (id, category_id, question, answer, sort_order)
SELECT id, category_id, question, answer, sort_order
FROM `google_search_console_sanbox`.faqs;

-- ---------- 4. email templates ----------

TRUNCATE TABLE email_templates;
TRUNCATE TABLE email_categories;

INSERT INTO email_categories (id, name)
SELECT id, name
FROM `google_search_console_sanbox`.email_categories;

INSERT INTO email_templates (id, name, category_id, subject, body)
SELECT id, name, category_id, subject, body
FROM `google_search_console_sanbox`.email_templates;

-- ---------- 5. documentation ----------

TRUNCATE TABLE documentation;
TRUNCATE TABLE documentation_categories;

INSERT INTO documentation_categories (id, name, sort_order)
SELECT id, name, sort_order
FROM `google_search_console_sanbox`.documentation_categories;

INSERT INTO documentation (id, category_id, slug, title, content, sort_order)
SELECT id, category_id, slug, title, content, sort_order
FROM `google_search_console_sanbox`.documentation;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------- 6. admin_users: merge, keep this app's own logins ----------

INSERT IGNORE INTO admin_users (id, username, email, password, role, status)
SELECT id, username, email, password, role, status
FROM `google_search_console_sanbox`.admin_users;

-- ---------- 7. bump auto-increment past the copied ids ----------

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

-- ---------- 8. verify ----------

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
