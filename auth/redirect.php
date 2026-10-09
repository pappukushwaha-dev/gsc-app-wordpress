<?php
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/credentials.php';

if (!$ecwidClientId || !$ecwidRedirectUri) {
    exit("Ecwid credentials not configured.");
}

$scope = 'public_storefront,read_catalog,read_store_profile';

$params = [
    'client_id'     => $ecwidClientId,
    'redirect_uri'  => $ecwidRedirectUri,
    'scope'         => $scope,
    'response_type' => 'code'
];

header("Location: https://my.ecwid.com/api/oauth/authorize?" . http_build_query($params));
exit;