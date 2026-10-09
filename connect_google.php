<?php
// /connect_google.php
ini_set('display_errors',1); error_reporting(E_ALL);
session_start();

require_once __DIR__.'/classes/GoogleSearchConsole.php';

$gsc = new GoogleSearchConsole();
$authUrl = $gsc->getAuthUrl();

$returnTo = isset($_GET['return']) ? $_GET['return'] : '/dashboard.php';

?>
<!doctype html>
<meta charset="utf-8">
<title>Connect Google</title>
<script>
  (function () {
    var url = <?= json_encode($authUrl) ?>;

    var w = window.open(url, "_blank", "width=520,height=680,noopener");
    if (!w) {
      window.top.location.href = url;
    } else {
      var i = setInterval(function () {
        if (w.closed) {
          clearInterval(i);
          try { window.top.postMessage({type:"googleConnected"}, "*"); } catch(e){}
          window.location.replace(<?= json_encode($returnTo) ?>);
        }
      }, 700);
    }
  })();
</script>
<p>If you are not redirected automatically,
   <a href="<?= htmlspecialchars($authUrl) ?>" target="_blank" rel="noopener">click here</a>.
</p>
