<?php
declare(strict_types=1);

$title = 'MCP Settings';
$subTitle = 'Connect your AI assistant with your Search Console data';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('h')) {
    function h($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

require_once __DIR__ . '/mcp-config.php';
$brandSvg = is_file(__DIR__ . '/includes/brand_svgs.php')
        ? require_once __DIR__ . '/includes/brand_svgs.php'
        : []; // page works even without brand icons

$instanceId = $_SESSION['instanceid'] ?? $_SESSION['instance_id'] ?? ($_GET['instance_id'] ?? ($_GET['instanceId'] ?? null));
$instanceId = is_string($instanceId) ? trim($instanceId) : null;

if (empty($instanceId)) {
    header('Location: sign-in.php');
    exit;
}

// ---- Google connection check (app DB) ----
$googleConnected = false;
$googleEmail = '';
try {
    require_once __DIR__ . '/includes/config.php'; // loads app .env (Dotenv)
        require_once __DIR__ . '/includes/db.php'; // provides $pdo
    $stmt = $pdo->prepare('SELECT email, connected FROM google_accounts WHERE `' . MCP_GA_COLUMN . '` = :iid ORDER BY id DESC LIMIT 1');
    $stmt->execute([':iid' => $instanceId]);
    $ga = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($ga) {
        $googleConnected = ((int)$ga['connected'] === 1);
        $googleEmail = (string)($ga['email'] ?? '');
    }
} catch (Throwable $e) {
    $googleConnected = false;
}

// ---- Internal MCP API helper ----
// Posts to the MCP server; retries once via the origin URL when the public
// (Cloudflare-fronted) route blocks or fails.
function mcpCurlPost(string $path, string $payload): array
{
    $attempts = [['url' => MCP_SERVER_URL . $path, 'verify' => true, 'host' => null]];
    if (defined('MCP_INTERNAL_FALLBACK_URL') && MCP_INTERNAL_FALLBACK_URL !== '') {
        $attempts[] = [
            'url'    => MCP_INTERNAL_FALLBACK_URL . $path,
            'verify' => false,
            'host'   => (string) (parse_url(MCP_SERVER_URL, PHP_URL_HOST) ?: ''),
        ];
    }

    $lastHttp = 0;
    $lastErr = '';
    foreach ($attempts as $a) {
        $headers = [
            'Content-Type: application/json',
            'X-Mcp-Internal-Secret: ' . MCP_INTERNAL_SECRET,
        ];
        if ($a['host'] !== null && $a['host'] !== '') {
            $headers[] = 'Host: ' . $a['host'];
        }
        $ch = curl_init($a['url']);
        $opts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'Makkpress-Server/1.0 (+https://makkpressapps.com)',
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if (!$a['verify']) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($response === false || $response === '' || $httpCode === 0 || $httpCode === 403) {
            $lastHttp = $httpCode;
            $lastErr = $curlErr;
            continue; // blocked/failed -> try next route
        }
        $decoded = is_string($response) ? json_decode($response, true) : null;
        return is_array($decoded) ? ($decoded + ['_http' => $httpCode]) : ['_http' => $httpCode];
    }
    return ['_http' => $lastHttp ?: -1, '_curl_error' => $lastErr];
}

function mcpApiCall(string $action, array $extra = []): ?array
{
    $payload = json_encode(array_merge([
        'action'           => $action,
        'platform'         => MCP_PLATFORM,
        'store_identifier' => $GLOBALS['instanceId'],
    ], $extra));
    return mcpCurlPost('/internal/store-mcp', $payload);
}

// ---- AJAX: pairing code generation (no page reload) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_generate'])) {
    if (!$googleConnected) {
        $response = ['ok' => false, 'error' => 'Connect your Google account first (Setup wizard, step 1).'];
    } else {
        $payload = json_encode([
            'platform'         => MCP_PLATFORM,
            'store_identifier' => $instanceId,
            'site_domain'      => $googleEmail !== '' ? $googleEmail : null,
        ]);
        $resp = mcpCurlPost('/internal/pairing-code', $payload);
        $httpCode = (int)($resp['_http'] ?? 0);

        if ($httpCode === 201 && isset($resp['code'])) {
            $response = [
                'ok'                 => true,
                'code'               => $resp['code'],
                'expires_in_seconds' => (int)($resp['expires_in_seconds'] ?? 600),
            ];
        } elseif ($httpCode === 429) {
            $response = ['ok' => false, 'error' => 'Too many codes requested. Please try again in a while.'];
        } else {
            $err = isset($resp['_curl_error']) && $resp['_curl_error'] !== '' ? ' (' . substr($resp['_curl_error'], 0, 60) . ')' : ' (HTTP ' . $httpCode . ')';
            $response = ['ok' => false, 'error' => 'Could not create a pairing code right now' . $err . '.'];
        }
    }

    // The app's includes can emit notices/warnings - never let them pollute the JSON.
    $json = json_encode($response);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo $json;
    exit;
}

// ---- Non-AJAX actions ----
$errorMsg = null;
$notice = null;      // one-time panel content (e.g., new API key)
$noticeTitle = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_key'])) {
    if (!$googleConnected) {
        $errorMsg = 'Connect your Google account first.';
    } else {
        $resp = mcpApiCall('api-key', ['site_domain' => $googleEmail !== '' ? $googleEmail : null]);
        if ($resp !== null && ($resp['_http'] ?? 0) === 201 && !empty($resp['key'])) {
            $noticeTitle = 'Your new API key';
            $notice = $resp['key'];
        } else {
            $errorMsg = 'Could not create an API key right now. Please try again.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disconnect_id'])) {
    mcpApiCall('revoke-assistant', ['id' => (int)$_POST['disconnect_id']]);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_key_id'])) {
    mcpApiCall('revoke-key', ['id' => (int)$_POST['revoke_key_id']]);
}

// ---- Current status ----
$status = null;
$assistants = [];
$apiKeys = [];
if ($googleConnected) {
    $status = mcpApiCall('status');
    if ($status !== null && ($status['_http'] ?? 0) === 200) {
        $assistants = $status['assistants'] ?? [];
        $apiKeys = $status['api_keys'] ?? [];
    }
}

include './partials/layouts/layoutTop.php';
?>

<style>
  .mcp-hero { background: linear-gradient(100deg, #eff6ff 0%, #dbeafe 55%, #e0e7ff 100%); }
  .mcp-btn-primary { background:#2563eb; }
  .mcp-btn-primary:hover { background:#1d4ed8; }
  .mcp-icon-box { background:#2563eb1a; color:#2563eb; }
</style>

<div class="p-4 md:p-6">
  <!-- Page heading -->
  <div class="flex items-center gap-3 mb-5">
    <div class="w-11 h-11 rounded-xl mcp-icon-box flex items-center justify-center text-xl">
      <iconify-icon icon="solar:link-circle-bold"></iconify-icon>
    </div>
    <div>
      <h1 class="text-xl font-bold text-gray-900 leading-tight">MCP Settings</h1>
      <p class="text-sm text-gray-500">Connect your AI assistant with your Search Console data</p>
    </div>
  </div>

  <?php if (!$googleConnected): ?>
    <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-800 max-w-3xl">
      <p class="font-semibold mb-1">Connect Google first</p>
      <p class="text-sm">Your Google Search Console account is not linked yet.
        Open the <a class="underline font-medium" href="setup-wizard.php?step=1">setup wizard (step 1)</a>,
        connect Google, then come back here.</p>
    </div>
  <?php else: ?>

    <?php if ($errorMsg !== null): ?>
      <div class="max-w-5xl mb-4 rounded-lg border border-red-200 bg-red-50 text-red-700 px-4 py-3 text-sm"><?= h($errorMsg) ?></div>
    <?php endif; ?>

    <!-- Hero banner -->
    <div class="max-w-5xl mcp-hero rounded-2xl border border-blue-100 p-6 md:p-7 mb-6 relative overflow-hidden">
      <div class="flex items-start justify-between gap-6">
        <div class="flex items-start gap-4">
          <div class="w-12 h-12 rounded-xl bg-white/80 shadow-sm flex items-center justify-center text-2xl">✨</div>
          <div>
            <h2 class="text-lg font-bold text-gray-900">AI assistant access</h2>
            <p class="text-sm text-gray-600 mt-1 max-w-xl">Let Claude, ChatGPT or another AI assistant read your Search Console reports. Assistants can read only. They cannot change your store.</p>
            <div class="mt-4">
              <button type="button" id="mcp-connect-btn" class="mcp-btn-primary inline-flex items-center gap-2 text-white rounded-lg px-5 py-2.5 text-sm font-semibold shadow-sm">
                <iconify-icon icon="solar:link-bold"></iconify-icon>
                Connect AI assistant
              </button>
            </div>
          </div>
        </div>
        <div class="hidden md:flex w-24 h-24 rounded-2xl bg-white shadow-sm items-center justify-center text-5xl relative">
          🤖
          <span class="absolute -top-1 -right-1 text-base">✨</span>
          <span class="absolute -bottom-1 -left-1 text-xs">✨</span>
        </div>
      </div>
    </div>

    <!-- Pairing panel (injected via AJAX, no page reload) -->
    <div id="mcp-panel-slot" class="max-w-5xl mb-5"></div>

    <?php if ($notice !== null): ?>
      <div class="max-w-5xl mb-5 rounded-xl border border-blue-200 bg-white p-5 shadow-sm">
        <p class="text-sm font-semibold text-gray-800 mb-2"><?= h($noticeTitle) ?></p>
        <p class="text-sm text-gray-500 mb-2">Copy it now — it will not be shown again.</p>
        <div class="bg-slate-900 text-blue-100 rounded-xl py-3 px-4 font-mono text-sm break-all select-all"><?= h($notice) ?></div>
      </div>
    <?php endif; ?>

    <!-- Two cards -->
    <div class="max-w-5xl grid md:grid-cols-2 gap-5">

      <!-- Connected assistants -->
      <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        <div class="flex items-start gap-3 mb-4">
          <div class="w-9 h-9 rounded-lg mcp-icon-box flex items-center justify-center">
            <iconify-icon icon="solar:users-group-rounded-bold"></iconify-icon>
          </div>
          <div>
            <h3 class="font-semibold text-gray-900">Connected assistants</h3>
            <p class="text-xs text-gray-500">Assistants that can read your Search Console reports.</p>
          </div>
        </div>

        <?php if ($assistants === []): ?>
          <div class="rounded-xl border border-dashed border-gray-300 py-8 text-center">
            <p class="text-sm font-medium text-gray-500">No assistants connected yet.</p>
            <p class="text-xs text-gray-400 mt-1">Click <b>Connect AI assistant</b> above to link Claude.</p>
          </div>
        <?php else: ?>
          <ul class="space-y-3">
            <?php foreach ($assistants as $a): ?>
              <li class="flex items-center justify-between rounded-xl border border-gray-100 bg-gray-50/60 px-4 py-3">
                <div class="flex items-center gap-3">
                  <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center">💬</div>
                  <div>
                    <p class="text-sm font-semibold text-gray-800"><?= h(ucfirst($a['client_name'] !== '' ? $a['client_name'] : 'Assistant')) ?></p>
                    <p class="text-xs text-gray-400">Added <?= h(substr((string)$a['created_at'], 0, 10)) ?>
                      <?= $a['last_used_at'] !== null ? ' · Last used ' . h(str_replace(' ', ' ', substr((string)$a['last_used_at'], 0, 16))) : '' ?></p>
                  </div>
                </div>
                <form method="post" onsubmit="return confirm('Disconnect this assistant?')">
                  <input type="hidden" name="disconnect_id" value="<?= (int)$a['id'] ?>">
                  <button type="submit" class="text-sm font-medium text-red-600 border border-red-200 rounded-lg px-3 py-1.5 hover:bg-red-50">Disconnect</button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <!-- API keys -->
      <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between mb-4">
          <div class="flex items-start gap-3">
            <div class="w-9 h-9 rounded-lg mcp-icon-box flex items-center justify-center">
              <iconify-icon icon="solar:key-square-2-bold"></iconify-icon>
            </div>
            <div>
              <h3 class="font-semibold text-gray-900">API keys</h3>
              <p class="text-xs text-gray-500">Manage your API keys for AI assistants.</p>
            </div>
          </div>
          <form method="post">
            <button type="submit" name="create_key" value="1" class="mcp-btn-primary text-white rounded-lg px-4 py-2 text-sm font-semibold">＋ Create API key</button>
          </form>
        </div>

        <?php if ($apiKeys === []): ?>
          <div class="rounded-xl border border-dashed border-gray-300 py-8 text-center px-4">
            <div class="text-2xl mb-1">🔑</div>
            <p class="text-sm font-medium text-gray-500">No API keys yet.</p>
            <p class="text-xs text-gray-400 mt-1">Create an API key to let an AI assistant read your Search Console reports.</p>
          </div>
        <?php else: ?>
          <ul class="space-y-3">
            <?php foreach ($apiKeys as $k): ?>
              <li class="flex items-center justify-between rounded-xl border border-gray-100 bg-gray-50/60 px-4 py-3">
                <div>
                  <p class="text-sm font-semibold text-gray-800">Key ····<?= h($k['key_hint']) ?>
                    <?php if (($k['status'] ?? '') !== 'active'): ?>
                      <span class="ml-1 text-xs text-gray-400">(disabled)</span>
                    <?php endif; ?>
                  </p>
                  <p class="text-xs text-gray-400">Created <?= h(substr((string)$k['created_at'], 0, 10)) ?>
                    <?= $k['last_used_at'] !== null ? ' · Last used ' . h(substr((string)$k['last_used_at'], 0, 16)) : ' · Never used' ?></p>
                </div>
                <?php if (($k['status'] ?? '') === 'active'): ?>
                  <form method="post" onsubmit="return confirm('Revoke this API key?')">
                    <input type="hidden" name="revoke_key_id" value="<?= (int)$k['id'] ?>">
                    <button type="submit" class="text-sm font-medium text-red-600 border border-red-200 rounded-lg px-3 py-1.5 hover:bg-red-50">Revoke</button>
                  </form>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <p class="max-w-5xl text-xs text-gray-400 mt-4">Read-only access · Encrypted OAuth · Disconnect anytime</p>
<?php endif; ?>
</div>

<script>
(function () {
  var btn = document.getElementById('mcp-connect-btn');
  if (!btn) return;
  var slot = document.getElementById('mcp-panel-slot');
  var mcpUrl = <?= json_encode(MCP_SERVER_URL . '/mcp') ?>;
  var brandSvg = <?= json_encode($brandSvg) ?>;

  var clients = {
    claude: {
      name: 'Claude', svg: brandSvg.claude, bg: '#fdece4', fg: '#d97757',
      steps: ['In Claude, open Settings, then Connectors, then Add custom connector.',
              'Paste ' + mcpUrl + ' and continue.',
              'When Claude asks, enter the pairing code above and click Allow.']
    },
    chatgpt: {
      name: 'ChatGPT', svg: brandSvg.chatgpt, bg: '#eef2f6', fg: '#111111',
      steps: ['Open ChatGPT → Settings → Connectors (developer mode).',
              'Add connector URL: ' + mcpUrl + '.',
              'Authenticate with the pairing code above.']
    },
    gemini: {
      name: 'Gemini', svg: brandSvg.gemini, bg: '#e8f0fe', fg: '#1a73e8',
      steps: ['Open your Gemini MCP client settings.',
              'Add MCP server: ' + mcpUrl + '.',
              'Complete OAuth with the pairing code above.']
    },
    perplexity: {
      name: 'Perplexity', svg: brandSvg.perplexity, bg: '#e6f7f4', fg: '#20808d',
      steps: ['Open Perplexity → Settings → MCP connectors.',
              'Add server URL: ' + mcpUrl + '.',
              'Authenticate with the pairing code above.']
    }
  };

  function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;'); }

  function buildPanel(code, expiresIn) {
    slot.innerHTML =
      '<div class="rounded-2xl border border-gray-200 bg-white p-5 md:p-6 shadow-sm">' +
        '<p class="flex items-center gap-2 text-sm font-medium text-gray-700 mb-4">' +
          '<span class="w-5 h-5 rounded-full bg-green-500 text-white flex items-center justify-center text-[11px]">✓</span>' +
          'Your pairing code. It works once and expires in ' +
          '<span id="mcp-countdown" class="font-bold text-blue-600">10:00</span>.' +
        '</p>' +
        '<div class="flex items-center justify-between gap-4 rounded-2xl bg-blue-50 border border-blue-100 px-5 md:px-8 py-6 mb-5">' +
          '<span id="mcp-code" class="text-3xl md:text-4xl font-extrabold tracking-[0.3em] text-blue-600 select-all">' + esc(code) + '</span>' +
          '<button type="button" data-copy="mcp-code" title="Copy code" class="shrink-0 w-10 h-10 rounded-xl bg-white border border-gray-200 shadow-sm text-gray-500 hover:text-blue-600 hover:border-blue-300 flex items-center justify-center">' +
            '<iconify-icon icon="solar:copy-outline" class="text-lg"></iconify-icon>' +
          '</button>' +
        '</div>' +
        '<div class="flex flex-wrap gap-2 mb-4" id="mcp-tabs">' +
          Object.keys(clients).map(function (k) {
            var c = clients[k];
            return '<button type="button" data-c="' + k + '" class="mcp-tab px-4 py-2 rounded-full border text-sm font-medium flex items-center gap-2 bg-white border-gray-300 text-gray-600">' +
              '<span class="inline-flex" style="color:' + c.fg + '">' + (c.svg ? c.svg.replace('<svg ', '<svg fill="' + c.fg + '" ') : '') + '</span> ' + c.name + '</button>';
          }).join('') +
        '</div>' +
        '<div class="rounded-2xl border border-gray-100 bg-white shadow-sm p-5">' +
          '<div class="flex items-center gap-3 mb-4">' +
            '<div id="mcp-step-logo" class="w-10 h-10 rounded-full flex items-center justify-center font-black text-sm"></div>' +
            '<div><p id="mcp-step-name" class="font-bold text-gray-900"></p>' +
            '<p class="text-xs text-gray-400">Keep this code handy. Generate it first, like you just did.</p></div>' +
          '</div>' +
          '<ol id="mcp-steps" class="space-y-3 text-sm text-gray-600"></ol>' +
          '<div class="mt-4 flex items-center justify-between gap-3 rounded-xl bg-blue-50 border border-blue-100 px-4 py-3">' +
            '<code id="mcp-url" class="text-blue-700 text-sm select-all">' + esc(mcpUrl) + '</code>' +
            '<button type="button" data-copy="mcp-url" title="Copy URL" class="shrink-0 w-9 h-9 rounded-lg bg-white border border-gray-200 shadow-sm text-gray-500 hover:text-blue-600 hover:border-blue-300 flex items-center justify-center">' +
              '<iconify-icon icon="solar:copy-outline"></iconify-icon>' +
            '</button>' +
          '</div>' +
        '</div>' +
      '</div>';

    function selectClient(key) {
      var c = clients[key] || { name: key, svg: '', bg: '#eef2f6', fg: '#334155', steps: [] };
      document.querySelectorAll('#mcp-tabs .mcp-tab').forEach(function (t) {
        var on = t.dataset.c === key;
        t.classList.toggle('border-blue-600', on);
        t.classList.toggle('text-blue-600', on);
        t.classList.toggle('bg-blue-50', on);
        t.classList.toggle('border-gray-300', !on);
        t.classList.toggle('text-gray-600', !on);
      });
      var logo = document.getElementById('mcp-step-logo');
      logo.style.background = c.bg;
      logo.innerHTML = c.svg ? c.svg.replace('<svg ', '<svg width="20" height="20" fill="' + c.fg + '" ') : esc(c.name.charAt(0));
      document.getElementById('mcp-step-name').textContent = c.name;
      document.getElementById('mcp-steps').innerHTML = c.steps.map(function (s, i) {
        return '<li class="flex items-start gap-3">' +
          '<span class="shrink-0 w-6 h-6 rounded-full bg-blue-600 text-white text-xs font-bold flex items-center justify-center">' + (i + 1) + '</span>' +
          '<span class="pt-0.5">' + esc(s) + '</span></li>';
      }).join('');
    }

    document.querySelectorAll('#mcp-tabs .mcp-tab').forEach(function (t) {
      t.addEventListener('click', function () { selectClient(t.dataset.c); });
    });
    selectClient('claude');

    var left = expiresIn || 600;
    var el = document.getElementById('mcp-countdown');
    var timer = setInterval(function () {
      left--;
      if (left <= 0) { clearInterval(timer); el.textContent = 'expired'; return; }
      var m = Math.floor(left / 60), s = left % 60;
      el.textContent = m + ':' + (s < 10 ? '0' : '') + s;
    }, 1000);

    document.querySelectorAll('[data-copy]').forEach(function (b) {
      b.addEventListener('click', function () {
        var text = document.getElementById(b.dataset.copy).textContent.trim();
        navigator.clipboard.writeText(text).then(function () {
          var old = b.innerHTML;
          b.innerHTML = '<iconify-icon icon="solar:check-circle-bold" class="text-lg text-green-500"></iconify-icon>';
          setTimeout(function () { b.innerHTML = old; }, 1500);
        });
      });
    });

    slot.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  btn.addEventListener('click', function () {
    if (btn.dataset.loading === '1') return;
    btn.dataset.loading = '1';
    var old = btn.innerHTML;
    btn.innerHTML = 'Generating code...';
    fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'ajax_generate=1'
    }).then(function (r) { return r.text(); }).then(function (t) {
      // Tolerant parse: app includes may print warnings around the JSON.
      var d = null;
      try { d = JSON.parse(t); } catch (e) {
        var m = t.match(/\{[\s\S]*"ok"[\s\S]*\}/);
        if (m) { try { d = JSON.parse(m[0]); } catch (e2) {} }
      }
      btn.dataset.loading = '';
      btn.innerHTML = old;
      if (!d) { alert('PARSE FAIL. Response start: ' + t.substring(0, 200)); return; }
      if (!d.ok) { alert((d.error) ? d.error : 'Server said not ok.'); return; }
      try {
        buildPanel(d.code, d.expires_in_seconds);
      } catch (e) {
        alert('PANEL ERROR: ' + e.message);
      }
    }).catch(function (err) {
      btn.dataset.loading = '';
      btn.innerHTML = old;
      alert('FETCH FAIL: ' + err.message);
    });
  });
})();
</script>

<?php include './partials/layouts/layoutBottom.php'; ?>
