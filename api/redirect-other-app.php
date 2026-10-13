<?php
// api/redirect-other-app.php
declare(strict_types=1);

/**
 * Records a click from the Other Apps page or the sidebar's app list,
 * and sends the user on to the app that was clicked.
 *
 * Those places point their buttons here with ?app_id=N. This endpoint
 * looks the app up, stores one row per click with the user's instance
 * and email, and 302s to the app's destination.
 *
 * Tracking must never delay or break the jump: if anything goes wrong
 * on the database side, the redirect still happens.
 */

require_once __DIR__ . '/../includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$instanceId = trim((string)($_GET['instance_id'] ?? $_SESSION['instanceid'] ?? $_SESSION['instance_id'] ?? ''));
$appId      = (int)($_GET['app_id'] ?? 0);

$fallback = 'Location: ' . APP_BASE . '/other-apps.php';

/* No user or no app means nothing to record - send the click on its
   way all the same, so the page is never worse than before. */
if ($instanceId === '' || $appId <= 0) {
    header($fallback);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id, title, button_link
        FROM other_apps
        WHERE id = :id AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([':id' => $appId]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$app) {
        header($fallback);
        exit;
    }

    /* The owner address is read once, at click time: the email is
       stored with the row. */
    $email = '';
    try {
        $q = $pdo->prepare("SELECT email FROM WpSite WHERE instance_id = :iid LIMIT 1");
        $q->execute([':iid' => $instanceId]);
        $email = (string)($q->fetchColumn() ?: '');
    } catch (Throwable $e) {
        // email is a nice-to-have on the row, never worth failing over
    }

    /* WordPress has no marketplace listing of its own - the app's
       stored button_link (the company page) is the destination, and
       the Other Apps page itself is the only fallback. */
    $dest = '';
    if (!empty($app['button_link'])) {
        $dest = (string)$app['button_link'];
    }

    if ($dest !== '') {
        try {
            $ins = $pdo->prepare("
                INSERT INTO other_app_clicks
                    (instance_id, email, other_app_id, app_name, source_platform)
                VALUES
                    (:iid, :email, :aid, :aname, 'wordpress')
            ");
            $ins->execute([
                ':iid'   => $instanceId,
                ':email' => $email,
                ':aid'   => (int)$app['id'],
                ':aname' => (string)$app['title'],
            ]);
        } catch (Throwable $e) {
            error_log('redirect-other-app insert: ' . $e->getMessage());
        }

        header('Location: ' . $dest);
        exit;
    }

    header($fallback);
    exit;

} catch (Throwable $e) {
    error_log('redirect-other-app: ' . $e->getMessage());
    header($fallback);
    exit;
}
