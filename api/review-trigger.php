<?php
declare(strict_types=1);

/**
 * POST /api/review-trigger.php
 *
 * Body: {"trigger": "plan_purchased" | "first_report" | "stats_improved" |
 *               "sitemap_submitted" | "action_center"}
 *
 * Marks a named trigger for the store in the session and answers whether
 * the review popup may open (first time for this trigger AND no submitted
 * review yet). The instance always comes from the session.
 *
 * The 'login' trigger needs no endpoint: it is driven by the session +
 * sessionStorage logic inside partials/review-popup.php.
 */

session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/review-triggers.php';

header('Content-Type: application/json');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false]);
    exit;
}

$instanceId = $_SESSION['instance_id'] ?? $_SESSION['instanceid'] ?? null;

$in = json_decode((string) file_get_contents('php://input'), true);
$trigger = trim((string) ($in['trigger'] ?? ''));

if (!$instanceId || !review_valid_trigger($trigger)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'eligible' => false]);
    exit;
}

if (review_is_submitted($pdo, (string) $instanceId)) {
    echo json_encode(['success' => true, 'eligible' => false, 'reason' => 'submitted']);
    exit;
}

$eligible = review_mark_trigger($pdo, (string) $instanceId, $trigger);

echo json_encode(['success' => true, 'eligible' => $eligible]);
