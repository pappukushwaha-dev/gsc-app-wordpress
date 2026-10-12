-- ============================================================
-- 06-wp-plans.sql   (WordPress platform)
--
-- Seeds pricing_plans for this platform. pricing.php, get_plans.php
-- and billing.php all read these rows.
--
-- IMPORTANT - Stripe price IDs:
--   The paid rows carry price_id = NULL. Stripe checkout works only
--   when price_id holds the REAL Stripe Price ID (price_1ABC...).
--   Create the products/prices in the Stripe dashboard, then:
--
--     UPDATE pricing_plans SET price_id = 'price_XXXX' WHERE name = 'Grow' AND billingPeriod = 'monthly';
--     UPDATE pricing_plans SET price_id = 'price_YYYY' WHERE name = 'Grow' AND billingPeriod = 'yearly';
--
--   The Stripe webhook (webhook/payment.php) maps incoming
--   subscriptions back to plans by this same price_id.
--
-- Safe to run more than once: seeded by code (unique on code).
-- ============================================================ */

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pricing_plans'
    AND COLUMN_NAME = 'price_id') = 0,
  'ALTER TABLE pricing_plans ADD COLUMN price_id VARCHAR(120) DEFAULT NULL AFTER planProductId',
  'SELECT ''pricing_plans.price_id already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

INSERT INTO pricing_plans
    (name, code, price, isRecurring, billingPeriod, planProductId, showInMarket, price_id)
SELECT * FROM (
    SELECT 'Free' AS name, 'free' AS code, 0 AS price, 0 AS isRecurring,
           'free' AS billingPeriod, 'basic' AS planProductId, 1 AS showInMarket, NULL AS price_id
) AS src
WHERE NOT EXISTS (SELECT 1 FROM pricing_plans WHERE code = 'free');

INSERT INTO pricing_plans
    (name, code, price, isRecurring, billingPeriod, planProductId, showInMarket, price_id)
SELECT * FROM (
    SELECT 'Grow' AS name, 'grow' AS code, 20 AS price, 1 AS isRecurring,
           'monthly' AS billingPeriod, '' AS planProductId, 1 AS showInMarket, NULL AS price_id
) AS src
WHERE NOT EXISTS (SELECT 1 FROM pricing_plans WHERE code = 'grow' AND billingPeriod = 'monthly');

INSERT INTO pricing_plans
    (name, code, price, isRecurring, billingPeriod, planProductId, showInMarket, price_id)
SELECT * FROM (
    SELECT 'Grow' AS name, 'grow' AS code, 200 AS price, 1 AS isRecurring,
           'yearly' AS billingPeriod, '' AS planProductId, 1 AS showInMarket, NULL AS price_id
) AS src
WHERE NOT EXISTS (SELECT 1 FROM pricing_plans WHERE code = 'grow' AND billingPeriod = 'yearly');

/* ------------------------------------------------------------
   Verify:
------------------------------------------------------------ */

SELECT id, name, code, price, billingPeriod, price_id FROM pricing_plans ORDER BY id;
