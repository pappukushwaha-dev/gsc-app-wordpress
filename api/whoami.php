<?php
declare(strict_types=1);

// api/whoami.php
ini_set('display_errors', 0);
error_reporting(0);

// include config so plan_guard can use $pdo if present
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/plan_guard.php';

// This will output: { success: true, plan: { ... }, allowed: bool }
whoamiResponseForClient();
