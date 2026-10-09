<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/config.php';

$instanceId = $_GET['instance_id'] ?? '';
if (!$instanceId) {
    echo json_encode(['error' => 'Missing instance_id']);
    exit;
}

try {

    /**
     * 1) CONNECT WITH GOOGLE
     *    How many Google accounts are connected for this instance?
     *    Table: google_accounts (column: instanceId)
     */
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM google_accounts 
        WHERE instanceId = :inst
    ");
    $stmt->execute([':inst' => $instanceId]);
    $connectedGoogleAccounts = (int)$stmt->fetchColumn();

    /**
     * 2) VERIFIED DOMAINS
     *    How many domains are verified in GSC for this instance?
     *    Table: gsc_domain_verifications
     *    Condition: verification_status = 'verified'
     */
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM gsc_domain_verifications
        WHERE instance_id = :inst
          AND verification_status = 'verified'
    ");
    $stmt->execute([':inst' => $instanceId]);
    $verifiedDomains = (int)$stmt->fetchColumn();

    /**
     * 3) SITEMAP SUBMIT
     *    How many sitemaps have been submitted for this instance?
     *    Table: sitemaps
     *    You can adjust the WHERE if you only want Success, etc.
     */
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM sitemaps
        WHERE instance_id = :inst
          AND last_submitted IS NOT NULL
    ");
    $stmt->execute([':inst' => $instanceId]);
    $submittedSitemaps = (int)$stmt->fetchColumn();

    /**
     * 4) "Your Domains" table data
     *    Used in the bottom table (Your Domains).
     *    We return domains + a last_update date.
     *
     *    last_update = most relevant timestamp from verification checks.
     */
    $domainsStmt = $pdo->prepare("
        SELECT 
            site_url AS domain,
            verification_status,
            COALESCE(
                verification_verified_at,
                verification_checked_at,
                updated_at,
                created_at
            ) AS last_update
        FROM gsc_domain_verifications
        WHERE instance_id = :inst
        ORDER BY last_update DESC
    ");
    $domainsStmt->execute([':inst' => $instanceId]);
    $domainsRaw = $domainsStmt->fetchAll(PDO::FETCH_ASSOC);

    $allUpdatedSchemas = array_map(function ($row) {
        return [
            'name'        => $row['domain'],        // used as "Domain" in the table
            'last_update' => $row['last_update'],   // used for "Last Updated"
            // 'status'    => $row['verification_status'], // available if you want to use later in JS
        ];
    }, $domainsRaw);

    /**
     * 5) "High Priority Schemas" (right box)
     *    For now: domains that are NOT yet verified (pending/failed).
     *    You can change this logic anytime.
     */
    $priorityStmt = $pdo->prepare("
        SELECT site_url
        FROM gsc_domain_verifications
        WHERE instance_id = :inst
          AND verification_status <> 'verified'
    ");
    $priorityStmt->execute([':inst' => $instanceId]);
    $highPrioritySchemas = $priorityStmt->fetchAll(PDO::FETCH_COLUMN);

    // ---- FINAL JSON (keep keys so your JS keeps working) ----
    echo json_encode([
        // Card 1: Domain Connect with Google
        'total_schemas'         => $connectedGoogleAccounts,

        // Card 2: Verified Domains
        'available_templates'   => $verifiedDomains,

        // Card 3: SiteMap Submit
        'updated_schemas'       => $submittedSitemaps,

        // "Your Domains" table
        'all_updated_schemas'   => $allUpdatedSchemas,

        // Right-side list ("High Priority Schemas" – here: domains not yet verified)
        'high_priority_schemas' => $highPrioritySchemas,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error'   => 'DB error',
        'details' => $e->getMessage()
    ]);
}
