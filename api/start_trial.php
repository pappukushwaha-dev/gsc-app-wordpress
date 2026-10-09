<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . '/../includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new Exception("Database connection missing.");
    }

    // Read body (JSON or form/query)
    $raw  = file_get_contents("php://input");
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = array_merge($_GET, $_POST);
    }
$instanceId = $_SESSION['instance_id'] ?? $_SESSION['instanceid'] ?? ($_GET['instance_id'] ?? null);

    if ($instanceId === "" || $instanceId === null) {
        echo json_encode([
            "success" => false,
            "error"   => "instance_id missing"
        ]);
        exit;
    }

    // --------------------------------------------------
    // STEP 1: CHECK IF INSTANCE HAS PAID SUBSCRIPTION
    // app_subscriptions(id, instance_id, plan_name, billing_period, status,
    //                  started_at, cancelled_at, expires_on, raw_event, updated_at)
    //
    // Assumption:
    //  - status = 'active' means paid
    //  - expires_on is NULL or in the future
    // --------------------------------------------------

 /* Ecwid's app_subscriptions differs from the other apps in two ways this
    check must honor (same rules as the sidebar plan resolver):
    - the Grow plan sits with billing_period = 'limited' and IS paid, so
      billing period alone cannot decide;
    - the status column is not maintained here - active/cancelled is
      computed from cancelled_at and expires_on dates. */
 $paidStmt = $pdo->prepare("
    SELECT id
    FROM app_subscriptions
    WHERE instance_id = :i
      AND (
            LOWER(billing_period) IN ('monthly', 'yearly', 'annual')
         OR LOWER(plan_name) LIKE '%grow%'
      )
      AND (expires_on IS NULL OR expires_on > NOW())
      AND (cancelled_at IS NULL OR (expires_on IS NOT NULL AND expires_on > NOW()))
    LIMIT 1
");

    $paidStmt->execute([':i' => $instanceId]);
    $hasPaid = (bool)$paidStmt->fetch(PDO::FETCH_ASSOC);

    if ($hasPaid) {
        echo json_encode([
            "success" => true,
            "skipped" => true,
            "reason"  => "instance_has_paid_plan"
        ]);
        exit;
    }

    // --------------------------------------------------
    // STEP 1b: ONE TRIAL PER STORE, EVER
    //
    // Uniqueness on instance_id alone is not enough if a reinstall mints a
    // new instance id. The guard is at store level - any prior trial on
    // ANY instance id of this Ecwid store blocks a new one.
    //
    // The old upsert also REFRESHED started_at/expires_on on conflict, so
    // even the same instance could restart its trial by clicking again.
    // A used trial now stays used.
    // --------------------------------------------------

    $storeStmt = $pdo->prepare("SELECT shop_domain FROM WpSite WHERE instance_id = :i LIMIT 1");
    $storeStmt->execute([':i' => $instanceId]);
    $shopDomain = (string)($storeStmt->fetchColumn() ?: '');

    if ($shopDomain !== '') {
        /* Two single-table queries instead of a JOIN: WpSite and
           app_free_trials carry different collations in this database
           (utf8mb4_0900_ai_ci vs utf8mb4_unicode_ci), and comparing their
           columns directly raises error 1267. */
        $idsStmt = $pdo->prepare("SELECT instance_id FROM WpSite WHERE shop_domain = :shop");
        $idsStmt->execute([':shop' => $shopDomain]);
        $siteInstanceIds = $idsStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!$siteInstanceIds) {
            $siteInstanceIds = [$instanceId];
        }

        $placeholders = implode(',', array_fill(0, count($siteInstanceIds), '?'));
        $prevStmt = $pdo->prepare("
            SELECT instance_id, expires_on
            FROM app_free_trials
            WHERE instance_id IN ($placeholders)
            ORDER BY expires_on DESC
            LIMIT 1
        ");
        $prevStmt->execute(array_values($siteInstanceIds));
        $prev = $prevStmt->fetch(PDO::FETCH_ASSOC);
    } else {
        /* No store row yet (fresh install) - fall back to an
           instance-level check so the guard never fully disappears. */
        $prevStmt = $pdo->prepare("SELECT instance_id, expires_on FROM app_free_trials WHERE instance_id = :i LIMIT 1");
        $prevStmt->execute([':i' => $instanceId]);
        $prev = $prevStmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($prev) {
        $stillActive = !empty($prev['expires_on']) && strtotime((string)$prev['expires_on']) > time();

        if ($stillActive && (string)$prev['instance_id'] === (string)$instanceId) {
            /* Their own trial is still running - idempotent OK. */
            echo json_encode([
                "success"    => true,
                "skipped"    => true,
                "reason"     => "trial_already_active",
                "expires_on" => $prev['expires_on'],
            ]);
            exit;
        }

        echo json_encode([
            "success" => false,
            "skipped" => true,
            "reason"  => "trial_already_used",
            "error"   => "The free preview has already been used on this store."
        ]);
        exit;
    }

    // --------------------------------------------------
    // STEP 2: ACTIVATE FREE TRIAL (14 DAYS)
    // app_free_trials(id, instance_id, status, started_at, expires_on,
    //                cancelled_at, converted_at, raw_event, created_at, updated_at)
    //
    // Requires UNIQUE KEY on instance_id in app_free_trials
    // --------------------------------------------------

    $now     = new DateTimeImmutable("now", new DateTimeZone("UTC"));
    $expires = $now->modify("+14 days");

    $startedAt = $now->format("Y-m-d H:i:s");
    $expiresOn = $expires->format("Y-m-d H:i:s");
    $createdAt = $startedAt;
    $updatedAt = $startedAt;

    $insert = $pdo->prepare("
        INSERT INTO app_free_trials (
            instance_id,
            status,
            started_at,
            expires_on,
            cancelled_at,
            converted_at,
            raw_event,
            created_at,
            updated_at
        ) VALUES (
            :instance_id,
            'active',
            :started_at,
            :expires_on,
            NULL,
            NULL,
            :raw_event,
            :created_at,
            :updated_at
        )
        ON DUPLICATE KEY UPDATE
            updated_at = VALUES(updated_at)
    ");

    $insert->execute([
        ":instance_id" => $instanceId,
        ":started_at"  => $startedAt,
        ":expires_on"  => $expiresOn,
        ":raw_event"   => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ":created_at"  => $createdAt,
        ":updated_at"  => $updatedAt,
    ]);

    echo json_encode([
        "success"     => true,
        "skipped"     => false,
        "instance_id" => $instanceId,
        "started_at"  => $startedAt,
        "expires_on"  => $expiresOn
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error"   => $e->getMessage()
    ]);
}
