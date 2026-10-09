<?php
require_once(__DIR__ . '/../includes/config.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

$response = [
    'success'  => false,
    'redirect' => null,
    'message'  => ''
];

if (!isset($_SESSION['instance_id'])) {
    $response['message'] = 'Instance ID missing.';
    echo json_encode($response);
    exit;
}

$instanceId = $_SESSION['instance_id'];
$baseUrl    = "https://makkpressapps.com/hl/google_search_console";
$planUrl    = $baseUrl . "/dashboard.php";

try {

    $hasPlan = false;

    // 🔹 Check PAID subscriptions (excluding lifetime)
    $paidSql = "
        SELECT id
        FROM app_subscriptions
        WHERE instance_id = :iid
          AND billing_period <> 'lifetime'
        LIMIT 1
    ";
    $stmt = $pdo->prepare($paidSql);
    $stmt->execute([':iid' => $instanceId]);

    if ($stmt->fetch()) {
        $hasPlan = true;
    }

    // 🔹 If no paid plan, check FREE TRIAL
    if (!$hasPlan) {
        $trialSql = "
            SELECT id
            FROM app_free_trials
            WHERE instance_id = :iid
            LIMIT 1
        ";
        $stmt = $pdo->prepare($trialSql);
        $stmt->execute([':iid' => $instanceId]);

        if ($stmt->fetch()) {
            $hasPlan = true;
        }
    }

    // 🔹 No plan → redirect to pricing
    if (!$hasPlan) {
        $response['success']  = true;
        $response['redirect'] = $planUrl;
        echo json_encode($response);
        exit;
    }

    // 🔹 Has plan → allow access, no redirect
    $response['success'] = true;
    $response['redirect'] = null;
    echo json_encode($response);

} catch (PDOException $e) {
    $response['message'] = $e->getMessage();
    echo json_encode($response);
}
