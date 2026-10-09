</div>

<?php include './partials/footer.php' ?>
</main>

<?php include './partials/script.php' ?>
<?php
// Prepare variables for ticket popup
if (!isset($siteUrl)) {
    $siteUrl = '';
    if (isset($instanceId) && $instanceId) {
        try {
            $stmt = $pdo->prepare("
                SELECT shop_domain
                FROM WpSite
                WHERE instance_id = ?
                LIMIT 1
            ");
            $stmt->execute([$instanceId]);
            $shopDomain = $stmt->fetchColumn();
            if ($shopDomain) {
                $siteUrl = 'https://' . $shopDomain;
            }
        } catch (Throwable $e) {
            // Ignore errors
        }
    }
}
include './partials/ticket-submit-popup.php';
?>
<div id="alert-container" class="fixed top-4 right-1/2 translate-x-1/2 space-y-3 z-50"></div>

</body>
</html>