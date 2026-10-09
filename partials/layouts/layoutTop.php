<!DOCTYPE html>
<html lang="en">
<?php include __DIR__ . '/../head.php' ?>
<?php
// Start session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Try to get shop from URL or session
$shop = $_GET['shop'] ?? $_SESSION['shop'] ?? null;

// If no shop but we have instanceId, get shop from database
if (!$shop) {
    // ✅ PRIORITIZE URL parameters (for admin "Login Into Store" functionality)
    $instanceId = $_GET['instanceid'] 
        ?? $_GET['instance_id'] 
        ?? $_SESSION['instance_id'] 
        ?? $_SESSION['instanceid'] 
        ?? null;
    
    if ($instanceId) {
        // Get shop domain from database
        require_once __DIR__ . '/../../includes/config.php';
        $stmt = $pdo->prepare("
            SELECT shop_domain
            FROM WpSite
            WHERE instance_id = ?
              AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$instanceId]);
        $shopDomain = $stmt->fetchColumn();
        
        if ($shopDomain) {
            $shop = $shopDomain;
            // Update session when instance_id comes from URL
            if (isset($_GET['instanceid']) || isset($_GET['instance_id'])) {
                $_SESSION['instance_id'] = $instanceId;
                $_SESSION['instanceid'] = $instanceId;
            }
        }
    }
}

// If still no shop, redirect to install (requires shop parameter)
if (!$instanceId) {
    header("Location: sign-in.php");
    exit();
}

$_SESSION['shop'] = $shop;
?>

<body class="dark:bg-neutral-800 bg-neutral-100 dark:text-white">

    <?php include __DIR__ . '/../sidebar.php' ?>

    <main class="dashboard-main">
        <?php include __DIR__ . '/../navbar.php' ?>

        <div class="dashboard-main-body">

            <?php //include __DIR__ . '/../breadcrumb.php' ?>

