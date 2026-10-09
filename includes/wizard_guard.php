<?php
// includes/wizard_guard.php

// ❗ NO session_start()
// ❗ NO redirects
// ❗ NO header() calls
// ❗ NO exit()

function getWizardStatus(string $instanceId): array {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT step1, step2, step3
        FROM setup_wizard_status
        WHERE instance_id = ?
        LIMIT 1
    ");
    $stmt->execute([$instanceId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'step1' => 0,
        'step2' => 0,
        'step3' => 0
    ];
}

function isWizardCompleted(string $instanceId): bool {
    $status = getWizardStatus($instanceId);

    return (
        (int)$status['step1'] === 1 &&
        (int)$status['step2'] === 1 &&
        (int)$status['step3'] === 1
    );
}
