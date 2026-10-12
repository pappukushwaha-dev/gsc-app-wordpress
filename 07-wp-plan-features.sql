-- ============================================================
-- 07-wp-plan-features.sql   (WordPress platform)
--
-- Loads the REAL plan/pricing data (exported from the live app
-- database and handed over as four dumps: pricing_plans,
-- plan_features, plan_feature_map, plan_feature_text).
--
--   Plans:  Free $0 | Grow $20/mo | Grow $134.40/yr ($11.20/mo)
--   Free:   only Google Indexing + Flexible Cancel Policy
--   Grow:   all 13 features, unlimited
--
-- The paid plan is 'Grow'/'grow' in this database - that is the
-- name plan_guard.php gates on (LIKE '%grow%') and the name the
-- Stripe webhook writes into app_subscriptions. The UI shows it
-- as "Organic Booster", same as the wix app.
--
-- Migrates away the earlier 'Pro'/'pro' seed if present.
--
-- Safe to run more than once: plan rows are created only when
-- missing, features are wiped and re-copied to the exact set.
-- Run 06-wp-plans.sql first on a fresh install.
-- ============================================================ */

-- ---------- 1. plans: converge on Free + Grow monthly/yearly ----------

UPDATE pricing_plans
SET name = 'Free', price = 0.00, isRecurring = 0, billingPeriod = 'free', showInMarket = 1
WHERE code = 'free';

-- the earlier seed called the paid plan Pro - retire it
DELETE FROM pricing_plans WHERE code = 'pro';

INSERT INTO pricing_plans (name, code, price, isRecurring, billingPeriod, planProductId, showInMarket, price_id)
SELECT 'Grow', 'grow', 20.00, 1, 'monthly', '', 1, NULL FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM pricing_plans WHERE code = 'grow' AND billingPeriod = 'monthly');

INSERT INTO pricing_plans (name, code, price, isRecurring, billingPeriod, planProductId, showInMarket, price_id)
SELECT 'Grow', 'grow', 134.40, 1, 'yearly', '', 1, NULL FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM pricing_plans WHERE code = 'grow' AND billingPeriod = 'yearly');

UPDATE pricing_plans SET name = 'Grow', price = 20.00,  isRecurring = 1, showInMarket = 1
WHERE code = 'grow' AND billingPeriod = 'monthly';

UPDATE pricing_plans SET name = 'Grow', price = 134.40, isRecurring = 1, showInMarket = 1
WHERE code = 'grow' AND billingPeriod = 'yearly';

-- ---------- 2. plan ids ----------

SELECT @free := id FROM pricing_plans WHERE code = 'free' AND billingPeriod = 'free'    ORDER BY id LIMIT 1;
SELECT @g_m   := id FROM pricing_plans WHERE code = 'grow' AND billingPeriod = 'monthly' ORDER BY id LIMIT 1;
SELECT @g_y   := id FROM pricing_plans WHERE code = 'grow' AND billingPeriod = 'yearly'  ORDER BY id LIMIT 1;

-- ---------- 3. features: wipe and load the real catalog ----------

DELETE FROM plan_feature_text;
DELETE FROM plan_feature_map;
DELETE FROM plan_features;

INSERT INTO plan_features (id, featureName, tooltip_text, sort_order) VALUES
(20, 'Google Indexing',                    'Submit and monitor your pages directly in Google Indexing for faster discovery.', 20),
(21, 'Analytics Access',                   'Access detailed Google Search Console analytics including clicks, impressions, and average position.', 21),
(22, 'Search Analytics',                   'Analyze search performance data such as queries, pages, countries, and devices.', 22),
(23, 'Automatic Sitemap Submission',       'Automatically submit your sitemap to Google whenever changes are detected.', 23),
(24, 'Query Tracking by Page & Location',  'Track search queries based on specific pages and geographic locations.', 24),
(25, 'Ranking Drop Detection',             'Get alerts when important pages start losing rankings in Google search results.', 25),
(26, 'Clicks & Impressions Insights',      'View detailed insights for clicks, impressions, and rankings across pages and products.', 26),
(27, 'SEO Opportunity Detection',          'Identify SEO opportunities and content gaps to improve organic visibility.', 27),
(28, 'CTR Tracking',                       'Monitor click-through rate changes over time to optimize titles and meta descriptions.', 28),
(29, 'Brand vs Non-Brand Keywords',        'Separate brand and non-brand keyword performance for better SEO strategy.', 29),
(30, 'Keyword Cannibalization Detection',  'Detect pages competing for the same keywords and harming rankings.', 30),
(31, 'Flexible Cancel Policy',             'Cancel or change your plan anytime with no long-term commitment.', 31),
(32, 'Email & Call Support',               'Get support via email or phone for setup, troubleshooting, and guidance.', 32);

-- ---------- 4. feature maps ----------
-- Free: Google Indexing (20) and Flexible Cancel Policy (31) included,
-- every other feature explicitly included=0 - exactly the source rows.
-- Grow (monthly and yearly): everything included and unlimited.

INSERT INTO plan_feature_map (plan_id, feature_id, included, limit_value, unlimited, meta) VALUES
(@free, 20, 1, NULL, 0, NULL),
(@free, 21, 0, NULL, 0, NULL),
(@free, 22, 0, NULL, 0, NULL),
(@free, 23, 0, NULL, 0, NULL),
(@free, 24, 0, NULL, 0, NULL),
(@free, 25, 0, NULL, 0, NULL),
(@free, 26, 0, NULL, 0, NULL),
(@free, 27, 0, NULL, 0, NULL),
(@free, 28, 0, NULL, 0, NULL),
(@free, 29, 0, NULL, 0, NULL),
(@free, 30, 0, NULL, 0, NULL),
(@free, 31, 1, NULL, 0, NULL),
(@free, 32, 0, NULL, 0, NULL),
(@g_m,  20, 1, NULL, 1, NULL),
(@g_m,  21, 1, NULL, 1, NULL),
(@g_m,  22, 1, NULL, 1, NULL),
(@g_m,  23, 1, NULL, 1, NULL),
(@g_m,  24, 1, NULL, 1, NULL),
(@g_m,  25, 1, NULL, 1, NULL),
(@g_m,  26, 1, NULL, 1, NULL),
(@g_m,  27, 1, NULL, 1, NULL),
(@g_m,  28, 1, NULL, 1, NULL),
(@g_m,  29, 1, NULL, 1, NULL),
(@g_m,  30, 1, NULL, 1, NULL),
(@g_m,  31, 1, NULL, 1, NULL),
(@g_m,  32, 1, NULL, 1, NULL),
(@g_y,  20, 1, NULL, 1, NULL),
(@g_y,  21, 1, NULL, 1, NULL),
(@g_y,  22, 1, NULL, 1, NULL),
(@g_y,  23, 1, NULL, 1, NULL),
(@g_y,  24, 1, NULL, 1, NULL),
(@g_y,  25, 1, NULL, 1, NULL),
(@g_y,  26, 1, NULL, 1, NULL),
(@g_y,  27, 1, NULL, 1, NULL),
(@g_y,  28, 1, NULL, 1, NULL),
(@g_y,  29, 1, NULL, 1, NULL),
(@g_y,  30, 1, NULL, 1, NULL),
(@g_y,  31, 1, NULL, 1, NULL),
(@g_y,  32, 1, NULL, 1, NULL);

-- ---------- 5. display texts (exactly as in the source data) ----------

INSERT INTO plan_feature_text (plan_id, feature_id, display_text) VALUES
(@free, 20, 'Google Indexing'),
(@free, 32, 'Basic Support'),
(@g_m,  20, 'Google indexing'),
(@g_m,  21, 'Full Search Analytics Access'),
(@g_m,  23, 'Automatic Sitemap Submission'),
(@g_m,  24, 'Track Search queries by Page, Location and more'),
(@g_m,  25, 'Find pages losing rankings early'),
(@g_m,  26, 'View clicks, impressions, and average rankings for individual pages, Categories, and products.'),
(@g_m,  27, 'Identify top content & missed SEO opportunities'),
(@g_m,  28, 'Track CTR improvements over time'),
(@g_m,  29, 'Brand vs. Non-Brand keyword data Split'),
(@g_m,  30, 'Identify Keyword cannibalization'),
(@g_m,  31, 'Flexible, Cancel-Anytime Policy'),
(@g_m,  32, 'Email and Call Support'),
(@g_y,  20, 'Google indexing'),
(@g_y,  21, 'Full Search Analytics Access'),
(@g_y,  23, 'Automatic Sitemap Submission'),
(@g_y,  24, 'Track Search queries by Page, Location and more'),
(@g_y,  25, 'Find pages losing rankings early'),
(@g_y,  26, 'View clicks, impressions, and average rankings for individual pages, Categories, and products.'),
(@g_y,  27, 'Identify top content & missed SEO opportunities'),
(@g_y,  28, 'Track CTR improvements over time'),
(@g_y,  29, 'Brand vs. Non-Brand keyword data Split'),
(@g_y,  30, 'Identify Keyword cannibalization'),
(@g_y,  31, 'Flexible, Cancel-Anytime Policy'),
(@g_y,  32, 'Email and Call Support');

-- ---------- 6. verify ----------

SELECT id, name, code, price, billingPeriod FROM pricing_plans ORDER BY id;

SELECT 'features' AS what, COUNT(*) AS rows_now FROM plan_features
UNION ALL SELECT 'feature_map',  COUNT(*) FROM plan_feature_map
UNION ALL SELECT 'feature_text', COUNT(*) FROM plan_feature_text;
