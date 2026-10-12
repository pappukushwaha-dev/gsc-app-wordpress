-- ============================================================
-- wix-plans-import.sql   (one-shot import - NOT a migration)
--
-- Copies the wix app's pricing into this WordPress database:
--   - plan prices   (Free $0, Grow $20/mo, Grow $134.40/yr)
--   - feature list  (13 features with their tooltips)
--   - per-plan feature maps + display texts
--
-- WP keeps its own plan codes ('free' / 'pro') so every code path
-- here keeps working; only prices, features and texts come over.
--
-- HOW TO RUN (phpMyAdmin):
--   1. First run:  SHOW DATABASES LIKE '%wix%';   and note the
--      wix database name.
--   2. Select the WORDPRESS database (wordpress-googlesearchconsole)
--      in the left panel.
--   3. Paste this whole file into the SQL tab and hit Go.
--   4. If the wix DB name is not `wix_googlesearchconsole`,
--      find-replace that name below first.
--
-- Safe to run more than once: plan rows are created only when
-- missing, features are wiped and re-copied to match wix exactly.
-- ============================================================ */

-- ---------- 1. make sure the three plan rows exist (06 fallback) ----------

INSERT INTO pricing_plans (name, code, price, isRecurring, billingPeriod, showInMarket)
SELECT 'Free', 'free', 0, 0, 'free', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM pricing_plans WHERE code = 'free');

INSERT INTO pricing_plans (name, code, price, isRecurring, billingPeriod, showInMarket)
SELECT 'Pro', 'pro', 20, 1, 'monthly', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM pricing_plans WHERE code = 'pro' AND billingPeriod = 'monthly');

INSERT INTO pricing_plans (name, code, price, isRecurring, billingPeriod, showInMarket)
SELECT 'Pro', 'pro', 200, 1, 'yearly', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM pricing_plans WHERE code = 'pro' AND billingPeriod = 'yearly');

-- ---------- 2. pull the prices from wix ----------

UPDATE pricing_plans p
JOIN (SELECT price FROM `wix_googlesearchconsole`.`pricing_plans` WHERE code = 'free' LIMIT 1) w
SET p.price = w.price
WHERE p.code = 'free';

UPDATE pricing_plans p
JOIN (SELECT price FROM `wix_googlesearchconsole`.`pricing_plans` WHERE code = 'grow' AND billingPeriod = 'monthly' LIMIT 1) w
SET p.price = w.price
WHERE p.code = 'pro' AND p.billingPeriod = 'monthly';

UPDATE pricing_plans p
JOIN (SELECT price FROM `wix_googlesearchconsole`.`pricing_plans` WHERE code = 'grow' AND billingPeriod = 'yearly' LIMIT 1) w
SET p.price = w.price
WHERE p.code = 'pro' AND p.billingPeriod = 'yearly';

-- ---------- 3. resolve ids on both sides ----------

SELECT @free  := id FROM pricing_plans WHERE code = 'free' AND billingPeriod = 'free'    ORDER BY id LIMIT 1;
SELECT @pro_m := id FROM pricing_plans WHERE code = 'pro'  AND billingPeriod = 'monthly' ORDER BY id LIMIT 1;
SELECT @pro_y := id FROM pricing_plans WHERE code = 'pro'  AND billingPeriod = 'yearly'  ORDER BY id LIMIT 1;

SELECT @w_free := id FROM `wix_googlesearchconsole`.`pricing_plans` WHERE code = 'free' LIMIT 1;
SELECT @w_m    := id FROM `wix_googlesearchconsole`.`pricing_plans` WHERE code = 'grow' AND billingPeriod = 'monthly' LIMIT 1;
SELECT @w_y    := id FROM `wix_googlesearchconsole`.`pricing_plans` WHERE code = 'grow' AND billingPeriod = 'yearly'  LIMIT 1;

-- ---------- 4. features: wipe and copy from wix (ids preserved) ----------

DELETE FROM plan_feature_text;
DELETE FROM plan_feature_map;
DELETE FROM plan_features;

-- WP has a sort_order column wix does not; the wix id keeps the same order.
INSERT INTO plan_features (id, featureName, tooltip_text, sort_order)
SELECT id, featureName, tooltip_text, id
FROM `wix_googlesearchconsole`.`plan_features`;

-- ---------- 5. feature maps + display texts (wix plan ids -> WP plan ids) ----------

INSERT INTO plan_feature_map (plan_id, feature_id, included, limit_value, unlimited, meta)
SELECT CASE m.plan_id WHEN @w_free THEN @free WHEN @w_m THEN @pro_m WHEN @w_y THEN @pro_y END,
       m.feature_id, m.included, m.limit_value, m.unlimited, m.meta
FROM `wix_googlesearchconsole`.`plan_feature_map` m
WHERE m.plan_id IN (@w_free, @w_m, @w_y);

INSERT INTO plan_feature_text (plan_id, feature_id, display_text)
SELECT CASE t.plan_id WHEN @w_free THEN @free WHEN @w_m THEN @pro_m WHEN @w_y THEN @pro_y END,
       t.feature_id, t.display_text
FROM `wix_googlesearchconsole`.`plan_feature_text` t
WHERE t.plan_id IN (@w_free, @w_m, @w_y);

-- ---------- 6. verify ----------

SELECT id, name, code, price, billingPeriod FROM pricing_plans ORDER BY id;
SELECT 'features' AS what, COUNT(*) AS rows_now FROM plan_features
UNION ALL SELECT 'feature_map', COUNT(*) FROM plan_feature_map
UNION ALL SELECT 'feature_text', COUNT(*) FROM plan_feature_text;
