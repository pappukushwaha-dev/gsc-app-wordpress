-- ============================================================
-- wix-plans-import.sql   (one-shot import - NOT a migration)
--
-- Copies the live wix app's pricing into the WordPress database:
--   - plan prices   (Free, Grow monthly, Grow yearly)
--   - feature list  (with tooltips)
--   - per-plan feature maps + display texts
--
-- HOW TO RUN (phpMyAdmin) - exactly two things:
--   1. In the LEFT panel, SELECT THE WIX DATABASE (click its name).
--   2. SQL tab -> paste this whole file -> Go.
--
-- Everything read unqualified comes from the wix DB you selected;
-- everything written goes to `wordpress-googlesearchconsole`.
--
-- Both databases have tables with the same names, so the script
-- starts with a canary: it only proceeds when the selected DB is
-- really the wix one (it owns the 'grow' plan). On the wrong DB it
-- stops with an "Unknown column" error before touching anything.
--
-- WP keeps its own plan codes ('free' / 'pro') so every code path
-- here keeps working; only prices, features and texts come over.
--
-- Safe to run more than once: plan rows are created only when
-- missing, features are wiped and re-copied to match wix exactly.
-- ============================================================ */

-- ---------- 0. safety: refuse to run on anything but the wix DB ----------

SELECT @canary := COUNT(*) FROM pricing_plans WHERE code = 'grow';

-- Only the wix DB owns the 'grow' plan. On the wrong DB this PREPAREs
-- a statement naming a table that does not exist there, and the error
-- stops the script before anything was written.
SET @gate_sql := IF(@canary > 0,
    'SELECT ''OK - wix database detected, importing...'' AS canary_check',
    'SELECT * FROM `___WRONG_DATABASE___SELECT_THE_WIX_DATABASE_IN_THE_LEFT_PANEL_AND_RUN_AGAIN___`');
PREPARE gate_stmt FROM @gate_sql;
EXECUTE gate_stmt;
DEALLOCATE PREPARE gate_stmt;

-- ---------- 1. make sure the three plan rows exist on the WP side ----------

INSERT INTO `wordpress-googlesearchconsole`.pricing_plans
    (name, code, price, isRecurring, billingPeriod, showInMarket)
SELECT 'Free', 'free', 0, 0, 'free', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `wordpress-googlesearchconsole`.pricing_plans WHERE code = 'free');

INSERT INTO `wordpress-googlesearchconsole`.pricing_plans
    (name, code, price, isRecurring, billingPeriod, showInMarket)
SELECT 'Pro', 'pro', 20, 1, 'monthly', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `wordpress-googlesearchconsole`.pricing_plans
                  WHERE code = 'pro' AND billingPeriod = 'monthly');

INSERT INTO `wordpress-googlesearchconsole`.pricing_plans
    (name, code, price, isRecurring, billingPeriod, showInMarket)
SELECT 'Pro', 'pro', 200, 1, 'yearly', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `wordpress-googlesearchconsole`.pricing_plans
                  WHERE code = 'pro' AND billingPeriod = 'yearly');

-- ---------- 2. pull the prices from wix ----------

UPDATE `wordpress-googlesearchconsole`.pricing_plans p
JOIN (SELECT price FROM pricing_plans WHERE code = 'free' LIMIT 1) w
SET p.price = w.price
WHERE p.code = 'free';

UPDATE `wordpress-googlesearchconsole`.pricing_plans p
JOIN (SELECT price FROM pricing_plans WHERE code = 'grow' AND billingPeriod = 'monthly' LIMIT 1) w
SET p.price = w.price
WHERE p.code = 'pro' AND p.billingPeriod = 'monthly';

UPDATE `wordpress-googlesearchconsole`.pricing_plans p
JOIN (SELECT price FROM pricing_plans WHERE code = 'grow' AND billingPeriod = 'yearly' LIMIT 1) w
SET p.price = w.price
WHERE p.code = 'pro' AND p.billingPeriod = 'yearly';

-- ---------- 3. resolve ids on both sides ----------

SELECT @free  := id FROM `wordpress-googlesearchconsole`.pricing_plans
 WHERE code = 'free' AND billingPeriod = 'free'    ORDER BY id LIMIT 1;
SELECT @pro_m := id FROM `wordpress-googlesearchconsole`.pricing_plans
 WHERE code = 'pro'  AND billingPeriod = 'monthly' ORDER BY id LIMIT 1;
SELECT @pro_y := id FROM `wordpress-googlesearchconsole`.pricing_plans
 WHERE code = 'pro'  AND billingPeriod = 'yearly'  ORDER BY id LIMIT 1;

SELECT @w_free := id FROM pricing_plans WHERE code = 'free' LIMIT 1;
SELECT @w_m    := id FROM pricing_plans WHERE code = 'grow' AND billingPeriod = 'monthly' LIMIT 1;
SELECT @w_y    := id FROM pricing_plans WHERE code = 'grow' AND billingPeriod = 'yearly'  LIMIT 1;

-- ---------- 4. features: wipe the WP side and copy from wix (ids preserved) ----------

DELETE FROM `wordpress-googlesearchconsole`.plan_feature_text;
DELETE FROM `wordpress-googlesearchconsole`.plan_feature_map;
DELETE FROM `wordpress-googlesearchconsole`.plan_features;

-- WP has a sort_order column wix does not; the wix id keeps the same order.
INSERT INTO `wordpress-googlesearchconsole`.plan_features (id, featureName, tooltip_text, sort_order)
SELECT id, featureName, tooltip_text, id
FROM plan_features;

-- ---------- 5. feature maps + texts (wix plan ids -> WP plan ids) ----------

INSERT INTO `wordpress-googlesearchconsole`.plan_feature_map
    (plan_id, feature_id, included, limit_value, unlimited, meta)
SELECT CASE m.plan_id WHEN @w_free THEN @free WHEN @w_m THEN @pro_m WHEN @w_y THEN @pro_y END,
       m.feature_id, m.included, m.limit_value, m.unlimited, m.meta
FROM plan_feature_map m
WHERE m.plan_id IN (@w_free, @w_m, @w_y);

INSERT INTO `wordpress-googlesearchconsole`.plan_feature_text
    (plan_id, feature_id, display_text)
SELECT CASE t.plan_id WHEN @w_free THEN @free WHEN @w_m THEN @pro_m WHEN @w_y THEN @pro_y END,
       t.feature_id, t.display_text
FROM plan_feature_text t
WHERE t.plan_id IN (@w_free, @w_m, @w_y);

-- ---------- 6. verify (reads the WP side) ----------

SELECT id, name, code, price, billingPeriod
FROM `wordpress-googlesearchconsole`.pricing_plans ORDER BY id;

SELECT 'features' AS what, COUNT(*) AS rows_now FROM `wordpress-googlesearchconsole`.plan_features
UNION ALL SELECT 'feature_map',  COUNT(*) FROM `wordpress-googlesearchconsole`.plan_feature_map
UNION ALL SELECT 'feature_text', COUNT(*) FROM `wordpress-googlesearchconsole`.plan_feature_text;
