<?php
// File: /admin/core/StoreApi.php
header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../includes/config.php';

/* ================= SAFETY CHECK ================= */
if (!isset($pdo)) {
    echo json_encode([
        'success' => false,
        'error'   => 'Database connection ($pdo) not available'
    ]);
    exit;
}

/* ================= INPUT ================= */
$page         = (int)($_GET['page'] ?? 1);
$limit        = (int)($_GET['limit'] ?? 10);
$search       = trim($_GET['search'] ?? '');
$store_status = strtolower(trim($_GET['store_status'] ?? 'all'));
$plan_status  = strtolower(trim($_GET['plan_status'] ?? 'all'));
$plan_key     = trim($_GET['plan_key'] ?? 'all');

if ($page < 1) $page = 1;
if ($limit < 1) $limit = 10;

$offset = ($page - 1) * $limit;

/* ================= WHERE + BINDS ================= */
$where = [];
$binds = [];

/* ✅ VALID SITE URL ONLY */
$where[] = "ws.site_url IS NOT NULL AND TRIM(ws.site_url) <> ''";

/* ❌ EXCLUDE MAKKPRESS */
$where[] = "(
    LOWER(IFNULL(ws.site_url,'')) NOT LIKE '%makkpress%'
    AND LOWER(IFNULL(wsp.company_name,'')) NOT LIKE '%makkpress%'
    AND LOWER(IFNULL(wsp.email,'')) NOT LIKE '%makkpress%'
)";

/* 🔍 SEARCH */
if ($search !== '') {
    $where[] = "(
        wsp.company_name LIKE ?
        OR wsp.email LIKE ?
        OR ws.site_url LIKE ?
        OR ws.site_display_name LIKE ?
        OR IFNULL(aps.plan_name,'') LIKE ?
        OR IFNULL(ga.email,'') LIKE ?
    )";

    $like = "%{$search}%";
    array_push($binds, $like, $like, $like, $like, $like, $like);
}

/* 🟢 STORE STATUS */
if ($store_status === 'active') {
    $where[] = "ws.event_type = ?";
    $binds[] = 'APPINSTALLED';
} elseif ($store_status === 'inactive') {
    $where[] = "ws.event_type <> ?";
    $binds[] = 'APPINSTALLED';
}

/* 🟣 PLAN STATUS */
if ($plan_status === 'active') {
    $where[] = "LOWER(IFNULL(aps.status,'')) = ?";
    $binds[] = 'active';
} elseif ($plan_status === 'inactive') {
    $where[] = "(IFNULL(aps.status,'') = '' OR LOWER(aps.status) <> ?)";
    $binds[] = 'active';
}

/* 🧾 PLAN NAME */
/* 🧾 PLAN NAME FILTER */
if ($plan_key !== 'all') {

    if ($plan_key === 'basic_monthly') {
        // REMOVED: OR IFNULL(aps.plan_name,'') = '' 
        // This ensures stores with NO record in app_subscriptions don't show up as Basic
        $where[] = "(
            LOWER(IFNULL(aps.plan_name,'')) LIKE ?
            OR LOWER(IFNULL(aps.plan_name,'')) LIKE ?
        )";
        $binds[] = '%basic%';
        $binds[] = '%free%';

    } elseif ($plan_key === 'grow_monthly') {
        $where[] = "LOWER(IFNULL(aps.plan_name,'')) LIKE ? AND LOWER(IFNULL(aps.billing_period,'')) = ?";
        $binds[] = '%grow%';
        $binds[] = 'monthly';

    } elseif ($plan_key === 'grow_yearly') {
        $where[] = "LOWER(IFNULL(aps.plan_name,'')) LIKE ? AND LOWER(IFNULL(aps.billing_period,'')) = ?";
        $binds[] = '%grow%';
        $binds[] = 'yearly';
    }
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

/* ================= COUNT QUERY ================= */
$countSql = "
    SELECT COUNT(DISTINCT ws.instance_id)
    FROM WpSite ws
    LEFT JOIN WpSiteProfile wsp
        ON ws.instance_id COLLATE utf8mb4_unicode_ci
         = wsp.instance_id COLLATE utf8mb4_unicode_ci
    LEFT JOIN app_subscriptions aps
        ON ws.instance_id COLLATE utf8mb4_unicode_ci
         = aps.instance_id COLLATE utf8mb4_unicode_ci
    LEFT JOIN google_accounts ga
        ON ga.instanceId COLLATE utf8mb4_unicode_ci
         = ws.instance_id COLLATE utf8mb4_unicode_ci
    LEFT JOIN gsc_domain_verifications gdv
        ON gdv.instance_id COLLATE utf8mb4_unicode_ci
         = ws.instance_id COLLATE utf8mb4_unicode_ci
    $whereSql
";

$stmt = $pdo->prepare($countSql);
$stmt->execute($binds);

$totalRecords = (int)$stmt->fetchColumn();
$totalPages   = (int)ceil($totalRecords / $limit);

/* ================= DATA QUERY ================= */
$dataSql = "
    SELECT
        ws.instance_id,
        ws.site_url,
        ws.site_display_name,
        ws.event_type,
        ws.created_at,
        wsp.company_name,
        wsp.email,
        wsp.logo_url,
        aps.plan_name,
        aps.status AS plan_status,
        aps.billing_period,
        aft.status AS free_trial_status,
        ga.email AS google_email,
        ga.connected AS google_connected,
        gdv.verification_status AS gsc_verification_status,
        gdv.meta_tag AS gsc_meta_tag,
        s.status AS sitemap_status
    FROM WpSite ws
    LEFT JOIN WpSiteProfile wsp
        ON ws.instance_id COLLATE utf8mb4_unicode_ci
         = wsp.instance_id COLLATE utf8mb4_unicode_ci
    LEFT JOIN app_subscriptions aps
        ON ws.instance_id COLLATE utf8mb4_unicode_ci
         = aps.instance_id COLLATE utf8mb4_unicode_ci
    LEFT JOIN app_free_trials aft
        ON ws.instance_id COLLATE utf8mb4_unicode_ci
         = aft.instance_id COLLATE utf8mb4_unicode_ci
    LEFT JOIN google_accounts ga
        ON ga.instanceId COLLATE utf8mb4_unicode_ci
         = ws.instance_id COLLATE utf8mb4_unicode_ci
    LEFT JOIN gsc_domain_verifications gdv
        ON gdv.instance_id COLLATE utf8mb4_unicode_ci
         = ws.instance_id COLLATE utf8mb4_unicode_ci
    LEFT JOIN sitemaps s
        ON s.instance_id COLLATE utf8mb4_unicode_ci
         = ws.instance_id COLLATE utf8mb4_unicode_ci
    $whereSql
    GROUP BY ws.instance_id
    ORDER BY ws.created_at DESC
    LIMIT ? OFFSET ?
";

$dataBinds = array_merge($binds, [$limit, $offset]);

$stmt = $pdo->prepare($dataSql);
foreach ($dataBinds as $i => $val) {
    $stmt->bindValue($i + 1, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->execute();

$stores = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================= FORMAT OUTPUT ================= */
foreach ($stores as &$s) {

    /* Store status */
    $s['status'] = ($s['event_type'] === 'APPINSTALLED') ? 'Active' : 'Inactive';

    /* Logo */
    if (!empty($s['logo_url'])) {
        $s['logo_url'] = 'https://static.wixstatic.com/media/' . ltrim($s['logo_url'], '/');
    }

    /* Plan normalization */
    $rawPlan   = strtolower(trim((string)($s['plan_name'] ?? '')));
    $billing   = strtolower(trim((string)($s['billing_period'] ?? '')));
    $rawStatus = strtolower(trim((string)($s['plan_status'] ?? '')));

    if ($rawPlan === '' || ($s['company_name'] === '' && $s['email'] === '')) {
        $s['plan_name']   = 'No Plan';
        $s['plan_status'] = null;
    } else {
        if (str_contains($rawPlan, 'free') || str_contains($rawPlan, 'basic')) {
            $s['plan_name'] = 'Basic';
        } elseif (str_contains($rawPlan, 'grow')) {
            $s['plan_name'] = ($billing === 'yearly')
                ? 'Organic Booster (Yearly)'
                : (($billing === 'monthly') ? 'Organic Booster (Monthly)' : 'Grow');
        } else {
            $s['plan_name'] = ucfirst($rawPlan);
        }
        $s['plan_status'] = ($rawStatus === 'active') ? 'active' : null;
    }

    /* Entitlement */
    $trialStatus = strtolower((string)($s['free_trial_status'] ?? ''));
    $s['entitlement_label'] = 'none';

    if ($trialStatus === 'active') {
        $s['entitlement_label'] = 'trial';
        $s['plan_name'] = 'Trial';
    } elseif ($s['plan_status'] === 'active') {
        $s['entitlement_label'] = 'active';
    }

    /* UI fallbacks */
    $s['google_email']        = $s['google_email'] ?? '-';
    $s['verification_status'] = $s['verification_status'] ?? 'Not Verified';
    $s['sitemap_status']      = $s['sitemap_status'] ?? 'Pending';
}

/* ================= RESPONSE ================= */
echo json_encode([
    'success'      => true,
    'stores'       => $stores,
    'totalRecords' => $totalRecords,
    'totalPages'   => $totalPages,
    'currentPage'  => $page,
    'limit'        => $limit
]);
