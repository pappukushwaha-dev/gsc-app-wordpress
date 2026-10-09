<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

$response = [
    'success' => false,
    'redirect' => null,
    'message' => ''
];

$instanceId = $_GET['instance_id'] ?? ($_SESSION['instanceid'] ?? null);
$instanceId = is_string($instanceId) ? trim($instanceId) : null;

if (!$instanceId) {
    $response['message'] = 'Instance ID missing';
    echo json_encode($response);
    exit;
}

$_SESSION['instanceid'] = $instanceId;

try {
    $stmt = $pdo->prepare("
        SELECT step1, step2
        FROM setup_wizard_status
        WHERE instance_id = ?
        LIMIT 1
    ");
    $stmt->execute([$instanceId]);
    $wizard = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($wizard && (int)$wizard['step1'] === 1 && (int)$wizard['step2'] === 1) {
        // ✅ Setup completed
        $response['redirect'] = 'dashboard.php?instance_id=' . urlencode($instanceId);
    } else {
        // ❌ Setup not completed or no record
        $response['redirect'] = 'setup-wizard.php?instance_id=' . urlencode($instanceId);
    }

    $response['success'] = true;
    echo json_encode($response);

} catch (PDOException $e) {
    $response['message'] = $e->getMessage();
    echo json_encode($response);
}
