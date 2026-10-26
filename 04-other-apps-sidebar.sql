-- ============================================================
-- 04-other-apps-sidebar.sql
--
-- Adds other_apps.is_sidebar - the admin's second toggle:
--   is_index   = 'enable' -> app shows in the dashboard's Other Apps strip
--   is_sidebar = 'enable' -> app shows in the user sidebar's Other Apps list
-- Both also require status = 'active' to appear anywhere.
--
-- Safe to run more than once.
-- ============================================================ */

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'other_apps'
    AND COLUMN_NAME = 'is_sidebar') = 0,
  'ALTER TABLE other_apps ADD COLUMN is_sidebar VARCHAR(10) NOT NULL DEFAULT ''disable'' AFTER is_index',
  'SELECT ''is_sidebar column already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;