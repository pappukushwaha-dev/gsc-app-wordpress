<?php
$title = 'Instructions';
$subTitle = 'Instructions';
include './partials/layouts/layoutTop.php';
?>

<style>
    .navbar-header,
    aside.sidebar.cstm-sidebar {
        display: none !important;
    }

    .dashboard-main-body {
        padding: 1rem 0 !important;
    }
    .dashboard-main {
        margin-left: auto !important;
        margin-right: auto !important;
        max-width: 80rem !important;
    }
</style>

<?php include './partials/instructions-section.php'; ?>


<?php include './partials/layouts/layoutBottom.php' ?>