<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php';

try {

  if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Missing PDO $pdo. Check includes/config.php');
    }

    // debug: ?debug=1
    $debug = isset($_GET['debug']) && in_array($_GET['debug'], ['1','true','yes'], true);

    // collect instance id from query/post/session
$instanceId = $_SESSION['instance_id'] ?? $_SESSION['instanceid'] ?? $_GET['instance_id'] ?? null;

    /* =========================================
       2) SITE URL (for pricing.php header)
    ========================================= */
    $siteUrl = null;
    if ($instanceId) {
        $siteStmt = $pdo->prepare("
            SELECT domain
            FROM WpSite
            WHERE instance_id = :iid
              AND is_active = 1
            LIMIT 1
        ");
        $siteStmt->execute([':iid' => $instanceId]);
        $shopDomain = $siteStmt->fetchColumn();
        if ($shopDomain) {
            $siteUrl = 'https://' . rtrim(strtolower(trim($shopDomain)), '/');
        }
    }

    /* =========================================
       3) PRICING PLANS
    ========================================= */
    $planStmt = $pdo->prepare("
        SELECT id, name, code, price, isRecurring, billingPeriod, price_id
        FROM pricing_plans
        WHERE COALESCE(showInMarket, 1) = 1
        ORDER BY id ASC
    ");
    $planStmt->execute();
    $plansRaw = $planStmt->fetchAll(PDO::FETCH_ASSOC);

    /* =========================================
       4) FEATURES MASTER
    ========================================= */
    $featStmt = $pdo->prepare("
        SELECT id, featureName, tooltip_text
        FROM plan_features
        ORDER BY sort_order ASC, id ASC
    ");
    $featStmt->execute();
    $featuresRaw = $featStmt->fetchAll(PDO::FETCH_ASSOC);

    /* =========================================
       5) FEATURE TEXT + MAP
    ========================================= */
    $featureTextByPlan = [];
    $txtStmt = $pdo->query("
        SELECT plan_id, feature_id, display_text
        FROM plan_feature_text
    ");
    foreach ($txtStmt as $r) {
        $featureTextByPlan[(int)$r['plan_id']][(int)$r['feature_id']] = $r['display_text'];
    }

    $mapsByPlan = [];
    $mapStmt = $pdo->query("
        SELECT plan_id, feature_id, included, limit_value, unlimited, meta
        FROM plan_feature_map
    ");
    foreach ($mapStmt as $r) {
        $mapsByPlan[(int)$r['plan_id']][(int)$r['feature_id']] = [
            'included'  => (bool)$r['included'],
            'limit'     => $r['limit_value'],
            'unlimited' => (bool)$r['unlimited'],
            'meta'      => $r['meta'] ? json_decode($r['meta'], true) : null
        ];
    }

    /* =========================================
       6) BUILD PLANS
    ========================================= */
    $plans = [];

    foreach ($plansRaw as $p) {
        $pid = (int)$p['id'];
        $features = [];

        foreach ($featuresRaw as $f) {
            $fid = (int)$f['id'];
            $map = $mapsByPlan[$pid][$fid] ?? [
                'included' => false,
                'limit' => null,
                'unlimited' => false,
                'meta' => null
            ];

            $features[] = [
                'id'           => $fid,
                'name'         => $f['featureName'],
                'tooltip'      => $f['tooltip_text'],
                'included'     => $map['included'],
                'display_text' => $featureTextByPlan[$pid][$fid]
                                    ?? ($map['included'] ? 'Included' : '-'),
                'limit'        => $map['limit'],
                'unlimited'    => $map['unlimited'],
                'meta'         => $map['meta']
            ];
        }

        $plans[] = [
            'id'            => $pid,
            'name'          => $p['name'],
            'price'         => (float)$p['price'],
            'isRecurring'   => (bool)$p['isRecurring'],
            'billingPeriod' => $p['billingPeriod'],
            'code'          => $p['code'] ?? strtolower(str_replace(' ', '-', $p['name'])),
            'price_id'      => $p['price_id'], // ✅ ADD THIS
            'features'      => $features
        ];
    }

    /* =========================================
       7) CURRENT SUBSCRIPTION / TRIAL
    ========================================= */
    $currentSubscription = null;

    $subStmt = $pdo->prepare("
        SELECT plan_name, billing_period, status, started_at, expires_on
        FROM app_subscriptions
        WHERE instance_id = :instid AND status = 'active'
        LIMIT 1
    ");
    $subStmt->execute([':instid' => $instanceId]);
    $sub = $subStmt->fetch(PDO::FETCH_ASSOC);

    if ($sub) {
        $sub['type'] = 'subscription';
        $currentSubscription = $sub;
    } else {
        $trialStmt = $pdo->prepare("
            SELECT status, started_at, expires_on
            FROM app_free_trials
            WHERE instance_id = :instid
            ORDER BY id DESC
            LIMIT 1
        ");
        $trialStmt->execute([':instid' => $instanceId]);
        $trial = $trialStmt->fetch(PDO::FETCH_ASSOC);

        if ($trial) {
            $trial['type'] = 'free_trial';
            $currentSubscription = $trial;
        }
    }

    /* =========================================
       8) RESPONSE
    ========================================= */
echo json_encode([
    'success'             => true,
    'site_url'            => $siteUrl,
    'plans'               => $plans,
    'features_master'     => $featuresRaw,
    'currentSubscription' => $currentSubscription
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);


} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $debug ? $e->getMessage() : 'Server error'
    ]);
}
