<?php
header('Content-Type: application/javascript');

$token = $_GET['token'] ?? '';
$token = preg_replace('/[^A-Za-z0-9_-]/', '', $token);

if (!$token) {
    exit;
}
?>

(function () {
  if (!document.querySelector('meta[name="google-site-verification"]')) {
    var meta = document.createElement('meta');
    meta.setAttribute('name', 'google-site-verification');
    meta.setAttribute('content', '<?php echo $token; ?>');
    document.head.appendChild(meta);
  }
})();
