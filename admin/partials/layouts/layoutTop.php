    <!-- meta tags and other links -->
    <!DOCTYPE html>
    <html lang="en">
    <?php include './partials/head.php'; // Require the core session manager and auth controller
require_once __DIR__ . '/../../../includes/config.php';

require_once __DIR__ . '/../../core/SessionManager.php';
require_once __DIR__ . '/../../core/AuthController.php';

// Start DB-backed session
SessionManager::startDatabaseSession();



?>

    <body class="dark:bg-neutral-800 bg-neutral-100 dark:text-white">

        <?php include './partials/sidebar.php' ?>

        <main class="dashboard-main">
            <?php include './partials/navbar.php' ?>

                <div class="dashboard-main-body">

                    <?php include './partials/breadcrumb.php' ?>