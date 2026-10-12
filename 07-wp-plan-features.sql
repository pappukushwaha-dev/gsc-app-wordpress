-- ============================================================
-- 07-wp-plan-features.sql   (WordPress platform)
--
-- Seeds the feature comparison rows that pricing.php and the
-- wizard's pricing block render: plan_features + the per-plan
-- map/text rows.
--
-- Facts used (all from this app's code, not marketing copy):
--   - free plan pings 10/day  (GSCWP_Ping FREE_DAILY_LIMIT)
--   - pro plan pings unlimited (plan gating is_paid)
--   - one website per dashboard (register-site is 1:1 with site_url)
--
-- Safe to run more than once: every insert is guarded by name or
-- the (plan_id, feature_id) unique keys, and updates in place.
--
-- Run 06-wp-plans.sql FIRST - this file looks up the plan ids
-- that migration seeds.
-- ============================================================ */

SET @free  := (SELECT id FROM pricing_plans WHERE code = 'free' LIMIT 1);
SET @pro_m := (SELECT id FROM pricing_plans WHERE code = 'pro' AND billingPeriod = 'monthly' LIMIT 1);
SET @pro_y := (SELECT id FROM pricing_plans WHERE code = 'pro' AND billingPeriod = 'yearly' LIMIT 1);

-- ---------------- feature catalog ----------------

INSERT INTO plan_features (featureName, tooltip_text, sort_order)
SELECT 'Google verification automation', 'The app generates the verification token and injects it into your site automatically - no code editing.', 10 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM plan_features WHERE featureName = 'Google verification automation');

INSERT INTO plan_features (featureName, tooltip_text, sort_order)
SELECT 'Sitemap submission', 'Submit and manage your sitemap in Google Search Console straight from the dashboard.', 20 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM plan_features WHERE featureName = 'Sitemap submission');

INSERT INTO plan_features (featureName, tooltip_text, sort_order)
SELECT 'Content update pings', 'Whenever your pages change, the app pings Google so they get re-crawled and indexed sooner.', 30 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM plan_features WHERE featureName = 'Content update pings');

INSERT INTO plan_features (featureName, tooltip_text, sort_order)
SELECT 'Search performance reports', 'Clicks, impressions, queries and top pages from Search Console, right inside your dashboard.', 40 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM plan_features WHERE featureName = 'Search performance reports');

INSERT INTO plan_features (featureName, tooltip_text, sort_order)
SELECT 'Websites per dashboard', 'How many websites one dashboard connection can cover.', 50 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM plan_features WHERE featureName = 'Websites per dashboard');

-- ---------------- free plan ----------------

INSERT INTO plan_feature_map (plan_id, feature_id, included, limit_value, unlimited)
SELECT @free, id, 1, NULL, 0 FROM plan_features WHERE featureName = 'Google verification automation' AND @free IS NOT NULL
ON DUPLICATE KEY UPDATE included = VALUES(included);

INSERT INTO plan_feature_map (plan_id, feature_id, included, limit_value, unlimited)
SELECT @free, id, 1, NULL, 0 FROM plan_features WHERE featureName = 'Sitemap submission' AND @free IS NOT NULL
ON DUPLICATE KEY UPDATE included = VALUES(included);

INSERT INTO plan_feature_map (plan_id, feature_id, included, limit_value, unlimited)
SELECT @free, id, 1, 10, 0 FROM plan_features WHERE featureName = 'Content update pings' AND @free IS NOT NULL
ON DUPLICATE KEY UPDATE included = VALUES(included), limit_value = VALUES(limit_value), unlimited = VALUES(unlimited);

INSERT INTO plan_feature_text (plan_id, feature_id, display_text)
SELECT @free, id, '10 per day' FROM plan_features WHERE featureName = 'Content update pings' AND @free IS NOT NULL
ON DUPLICATE KEY UPDATE display_text = VALUES(display_text);

INSERT INTO plan_feature_map (plan_id, feature_id, included, limit_value, unlimited)
SELECT @free, id, 1, NULL, 0 FROM plan_features WHERE featureName = 'Search performance reports' AND @free IS NOT NULL
ON DUPLICATE KEY UPDATE included = VALUES(included);

INSERT INTO plan_feature_map (plan_id, feature_id, included, limit_value, unlimited)
SELECT @free, id, 0, NULL, 0 FROM plan_features WHERE featureName = 'Websites per dashboard' AND @free IS NOT NULL
ON DUPLICATE KEY UPDATE included = VALUES(included);

INSERT INTO plan_feature_text (plan_id, feature_id, display_text)
SELECT @free, id, '-' FROM plan_features WHERE featureName = 'Websites per dashboard' AND @free IS NOT NULL
ON DUPLICATE KEY UPDATE display_text = VALUES(display_text);

-- ---------------- pro monthly ----------------

INSERT INTO plan_feature_map (plan_id, feature_id, included, limit_value, unlimited)
SELECT @pro_m, id, 1, NULL, 0 FROM plan_features WHERE featureName = 'Google verification automation' AND @pro_m IS NOT NULL
ON DUPLICATE KEY UPDATE included = VALUES(included);

INSERT INTO plan_feature_map (plan_id, feature_id, included, limit_value, unlimited)
SELECT @pro_m, id, 1, NULL, 0 FROM plan_features WHERE featureName = 'Sitemap submission' AND @pro_m IS NOT NULL
ON DUPLICATE KEY UPDATE included = VALUES(included);

INSERT INTO plan_feature_map (plan_id, feature_id, included, limit_value, unlimited)
SELECT @pro_m, id, 1, NULL, 1 FROM plan_features WHERE featureName = 'Content update pings' AND @pro_m IS NOT NULL
ON DUPLICATE KEY UPDATE included = VALUES(included), limit_value = VALUES(limit_value), unlimited = VALUES(unlimited);

INSERT INTO plan_feature_text (plan_id, feature_id, display_text)
SELECT @pro_m, id, 'Unlimited' FROM plan_features WHERE featureName = 'Content update pings' AND @pro_m IS NOT NULL
ON DUPLICATE KEY UPDATE display_text = VALUES(display_text);

INSERT INTO plan_feature_map (plan_id, feature_id, included, limit_value, unlimited)
SELECT @pro_m, id, 1, NULL, 0 FROM plan_features WHERE featureName = 'Search performance reports' AND @pro_m IS NOT NULL
ON DUPLICATE KEY UPDATE included = VALUES(included);

INSERT INTO plan_feature_map (plan_id, feature_id, included, limit_value, unlimited)
SELECT @pro_m, id, 0, NULL, 0 FROM plan_features WHERE featureName = 'Websites per dashboard' AND @pro_m IS NOT NULL
ON DUPLICATE KEY UPDATE included = VALUES(included);

INSERT INTO plan_feature_text (plan_id, feature_id, display_text)
SELECT @pro_m, id, '-' FROM plan_features WHERE featureName = 'Websites per dashboard' AND @pro_m IS NOT NULL
ON DUPLICATE KEY UPDATE display_text = VALUES(display_text);

-- ---------------- pro yearly ----------------

INSERT INTO plan_feature_map (plan_id, feature_id, included, limit_value, unlimited)
SELECT @pro_y, id, 1, NULL, 0 FROM plan_features WHERE featureName = 'Google verification automation' AND @pro_y IS NOT NULL
ON DUPLICATE KEY UPDATE included = VALUES(included);

INSERT INTO plan_feature_map (plan_id, feature_id, included, limit_value, unlimited)
SELECT @pro_y, id, 1, NULL, 0 FROM plan_features WHERE featureName = 'Sitemap submission' AND @pro_y IS NOT NULL
ON DUPLICATE KEY UPDATE included = VALUES(included);

INSERT INTO plan_feature_map (plan_id, feature_id, included, limit_value, unlimited)
SELECT @pro_y, id, 1, NULL, 1 FROM plan_features WHERE featureName = 'Content update pings' AND @pro_y IS NOT NULL
ON DUPLICATE KEY UPDATE included = VALUES(included), limit_value = VALUES(limit_value), unlimited = VALUES(unlimited);

INSERT INTO plan_feature_text (plan_id, feature_id, display_text)
SELECT @pro_y, id, 'Unlimited' FROM plan_features WHERE featureName = 'Content update pings' AND @pro_y IS NOT NULL
ON DUPLICATE KEY UPDATE display_text = VALUES(display_text);

INSERT INTO plan_feature_map (plan_id, feature_id, included, limit_value, unlimited)
SELECT @pro_y, id, 1, NULL, 0 FROM plan_features WHERE featureName = 'Search performance reports' AND @pro_y IS NOT NULL
ON DUPLICATE KEY UPDATE included = VALUES(included);

INSERT INTO plan_feature_map (plan_id, feature_id, included, limit_value, unlimited)
SELECT @pro_y, id, 0, NULL, 0 FROM plan_features WHERE featureName = 'Websites per dashboard' AND @pro_y IS NOT NULL
ON DUPLICATE KEY UPDATE included = VALUES(included);

INSERT INTO plan_feature_text (plan_id, feature_id, display_text)
SELECT @pro_y, id, '-' FROM plan_features WHERE featureName = 'Websites per dashboard' AND @pro_y IS NOT NULL
ON DUPLICATE KEY UPDATE display_text = VALUES(display_text);

-- ---------------- verify ----------------

SELECT 'plans' AS what, COUNT(*) AS rows_now FROM pricing_plans
UNION ALL
SELECT 'features', COUNT(*) FROM plan_features
UNION ALL
SELECT 'feature_map', COUNT(*) FROM plan_feature_map
UNION ALL
SELECT 'feature_text', COUNT(*) FROM plan_feature_text;
