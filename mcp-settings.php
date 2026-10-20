<?php
declare(strict_types=1);

$title = 'MCP Settings';
$subTitle = 'AI assistant connections';

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
    : [];

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
    require_once __DIR__ . '/includes/config.php';
    require_once __DIR__ . '/includes/db.php';
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

// ---- Internal MCP API helper (Cloudflare-loop safe: falls back to origin) ----
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
            continue;
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

    $json = json_encode($response);
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');
    echo $json;
    exit;
}

// ---- Non-AJAX actions ----
$errorMsg = null;
$notice = null;
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
  .mcp-page { max-width: 46rem; }
  .mcp-hero { display:flex; align-items:center; gap:12px; margin-bottom:1.25rem; }
  .mcp-hero .ic { width:40px; height:40px; border-radius:10px; background:var(--mcp-accent-soft,#e7f1fb); color:#2563eb; display:flex; align-items:center; justify-content:center; }
  .mcp-hero h1 { margin:0; font-size:22px; font-weight:700; display:flex; align-items:center; gap:8px; }
  .mcp-hero .mcp-badge { font-size:11px; padding:2px 8px; border-radius:6px; background:#f1f5f9; color:#64748b; border:0.5px solid #e2e8f0; }
  .mcp-hero p { margin:0; font-size:13px; color:#64748b; }
  .mcp-card { background:#fff; border:0.5px solid #e2e8f0; border-radius:12px; padding:1rem 1.25rem; margin-bottom:1rem; }
  .mcp-hero-banner { background:#e7f1fb; border:0.5px solid #bfdbfe; border-radius:12px; padding:1rem 1.25rem; margin-bottom:1rem; display:flex; gap:12px; }
  .mcp-hero-banner h2 { margin:0 0 4px; font-size:16px; }
  .mcp-hero-banner p { margin:0 0 12px; font-size:13px; line-height:1.6; color:#64748b; }
  .mcp-btn { display:inline-flex; align-items:center; gap:6px; background:#2563eb; color:#fff; border:none; border-radius:8px; padding:8px 14px; font-size:13px; font-weight:600; cursor:pointer; }
  .mcp-btn:hover { background:#1d4ed8; }
  .mcp-btn[disabled] { opacity:.6; cursor:wait; }
  .mcp-code-row { display:flex; align-items:center; justify-content:space-between; gap:8px; background:#f8fafc; border-radius:10px; padding:12px 16px; }
  .mcp-code-row .code { font-family:ui-monospace,monospace; font-size:26px; font-weight:500; letter-spacing:4px; color:#2563eb; }
  .mcp-code-row .cp { background:none; border:none; cursor:pointer; color:#64748b; font-size:16px; padding:4px; }
  .mcp-code-row .cp:hover { color:#2563eb; }
  .mcp-tab { font-size:13px; padding:6px 14px; border-radius:999px; cursor:pointer; background:#fff; border:0.5px solid #e2e8f0; color:#64748b; }
  .mcp-tab.on { border:1px solid #2563eb; color:#2563eb; background:#e7f1fb; }
  .mcp-step { display:flex; gap:10px; align-items:flex-start; margin:0 0 10px; font-size:13px; line-height:1.6; }
  .mcp-step .num { flex:none; width:22px; height:22px; border-radius:50%; background:#2563eb; color:#fff; font-size:12px; display:flex; align-items:center; justify-content:center; margin-top:1px; }
  .mcp-url { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-top:6px; border:0.5px solid #e2e8f0; border-radius:10px; padding:6px 6px 6px 12px; }
  .mcp-url code { font-family:ui-monospace,monospace; font-size:12px; overflow-x:auto; }
  .mcp-url button { background:none; border:none; cursor:pointer; color:#64748b; font-size:15px; padding:2px; }
  .mcp-url button:hover { color:#2563eb; }
  .mcp-empty { border:0.5px dashed #94a3b8; border-radius:10px; padding:1.25rem; text-align:center; }
  .mcp-empty p { margin:0; font-size:14px; font-weight:500; }
  .mcp-empty p + p { margin:4px 0 0; font-size:12px; color:#64748b; font-weight:400; }
  .mcp-badge-connected { display:inline-block; font-size:11px; font-weight:600; padding:1px 8px; border-radius:999px; background:#dcfce7; color:#16a34a; }
  .mcp-badge-created { display:inline-block; font-size:11px; font-weight:600; padding:1px 8px; border-radius:999px; background:#dbeafe; color:#2563eb; }
  .mcp-badge-deleted { display:inline-block; font-size:11px; font-weight:600; padding:1px 8px; border-radius:999px; background:#f1f5f9; color:#94a3b8; }
  .mcp-del { font-size:13px; font-weight:500; color:#dc2626; background:#fff; border:0.5px solid #fecaca; border-radius:8px; padding:5px 12px; cursor:pointer; }
  .mcp-del:hover { background:#fef2f2; }
</style>

<div class="mcp-page">
  <!-- Page header -->
  <div class="mcp-hero">
    <div class="ic"><iconify-icon icon="solar:link-circle-bold" width="22"></iconify-icon></div>
    <div>
      <h1>AI assistant connections <span class="mcp-badge">MCP</span></h1>
      <p>Let AI assistants like Claude and ChatGPT read your Search Console data.</p>
    </div>
  </div>

  <?php if (!$googleConnected): ?>
    <div class="mcp-card" style="border-color:#fca5a5;background:#fef2f2;">
      <h2 style="margin:0 0 4px;font-size:16px;">Connect Google first</h2>
      <p style="margin:0;font-size:13px;color:#64748b;">Your Google Search Console account is not linked yet.
        Open the <a href="setup-wizard.php?step=1" style="text-decoration:underline;">setup wizard (step 1)</a>, connect Google, then come back here.</p>
    </div>
  <?php else: ?>

    <?php if ($errorMsg !== null): ?>
      <div style="max-width:none;margin-bottom:1rem;border-radius:10px;border:0.5px solid #fecaca;background:#fef2f2;color:#b91c1c;padding:10px 14px;font-size:13px;"><?= h($errorMsg) ?></div>
    <?php endif; ?>

    <?php if ($notice !== null): ?>
      <div class="mcp-card">
        <p style="margin:0 0 6px;font-size:14px;font-weight:600;"><?= h($noticeTitle) ?></p>
        <p style="margin:0 0 8px;font-size:12px;color:#64748b;">Copy it now — it will not be shown again.</p>
        <div class="mcp-code-row"><span class="code" style="font-size:14px;letter-spacing:1px;"><?= h($notice) ?></span></div>

        <p style="margin:16px 0 8px;font-size:13px;font-weight:600;">Connect via CLI / config</p>
        <p style="margin:0 0 8px;font-size:12px;color:#64748b;">Paste this key in any MCP client that supports header authentication:</p>

        <details style="margin-bottom:8px;border:0.5px solid #e2e8f0;border-radius:10px;padding:0 12px;">
          <summary style="cursor:pointer;padding:10px 0;font-weight:500;font-size:13px;">Claude Code (terminal)</summary>
          <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;background:#f8fafc;border-radius:8px;padding:10px;">
            <code style="font-size:12px;overflow-x:auto;white-space:pre-wrap;word-break:break-all;">claude mcp add --transport http orgalytics-gsc https://mcp.orgalytics.ai/mcp --header "Authorization: Bearer <?= h($notice) ?>"</code>
            <button type="button" class="cp" data-copy-text='claude mcp add --transport http orgalytics-gsc https://mcp.orgalytics.ai/mcp --header "Authorization: Bearer <?= h($notice) ?>"' aria-label="Copy" style="background:none;border:none;cursor:pointer;color:#64748b;font-size:15px;flex:none;"><iconify-icon icon="solar:copy-outline"></iconify-icon></button>
          </div>
        </details>

        <details style="margin-bottom:8px;border:0.5px solid #e2e8f0;border-radius:10px;padding:0 12px;">
          <summary style="cursor:pointer;padding:10px 0;font-weight:500;font-size:13px;">Cursor</summary>
          <p style="margin:6px 0;font-size:12px;color:#64748b;">Add to <code>.cursor/mcp.json</code>:</p>
          <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;background:#f8fafc;border-radius:8px;padding:10px;">
            <code style="font-size:12px;white-space:pre-wrap;word-break:break-all;">{ "mcpServers": { "orgalytics-gsc": { "url": "https://mcp.orgalytics.ai/mcp", "headers": { "Authorization": "Bearer <?= h($notice) ?>" } } } }</code>
            <button type="button" class="cp" data-copy-text='{ "mcpServers": { "orgalytics-gsc": { "url": "https://mcp.orgalytics.ai/mcp", "headers": { "Authorization": "Bearer <?= h($notice) ?>" } } } }' aria-label="Copy" style="background:none;border:none;cursor:pointer;color:#64748b;font-size:15px;flex:none;"><iconify-icon icon="solar:copy-outline"></iconify-icon></button>
          </div>
        </details>

        <details style="border:0.5px solid #e2e8f0;border-radius:10px;padding:0 12px;">
          <summary style="cursor:pointer;padding:10px 0;font-weight:500;font-size:13px;">VS Code</summary>
          <p style="margin:6px 0;font-size:12px;color:#64748b;">Add to <code>.vscode/mcp.json</code>:</p>
          <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;background:#f8fafc;border-radius:8px;padding:10px;">
            <code style="font-size:12px;white-space:pre-wrap;word-break:break-all;">{ "servers": { "orgalytics-gsc": { "type": "http", "url": "https://mcp.orgalytics.ai/mcp", "headers": { "Authorization": "Bearer <?= h($notice) ?>" } } } }</code>
            <button type="button" class="cp" data-copy-text='{ "servers": { "orgalytics-gsc": { "type": "http", "url": "https://mcp.orgalytics.ai/mcp", "headers": { "Authorization": "Bearer <?= h($notice) ?>" } } } }' aria-label="Copy" style="background:none;border:none;cursor:pointer;color:#64748b;font-size:15px;flex:none;"><iconify-icon icon="solar:copy-outline"></iconify-icon></button>
          </div>
        </details>

        <p style="margin:10px 0 0;font-size:12px;color:#64748b;display:flex;align-items:center;gap:4px;">
          <iconify-icon icon="solar:shield-check-bold" width="13" style="color:#16a34a;"></iconify-icon>
          Deleting this key from the list above cuts access for everyone using it.
        </p>
      </div>

      <script>
        document.querySelectorAll('[data-copy-text]').forEach(function (b) {
          b.addEventListener('click', function () {
            navigator.clipboard.writeText(b.dataset.copyText).then(function () {
              var i = b.querySelector('iconify-icon');
              if (i) { i.setAttribute('icon', 'solar:check-circle-bold'); i.style.color = '#16a34a'; }
              setTimeout(function () { if (i) { i.setAttribute('icon', 'solar:copy-outline'); i.style.color = ''; } }, 1500);
            });
          });
        });
      </script>
    <?php endif; ?>

    <!-- Hero banner -->
    <div class="mcp-hero-banner">
      <iconify-icon icon="solar:sparkles-bold" width="22" style="color:#2563eb;margin-top:2px;"></iconify-icon>
      <div style="flex:1;">
        <h2>Connect an AI assistant</h2>
        <p>Give Claude or ChatGPT read-only access to your Search Console reports. Assistants can view your data but can't make any changes.</p>
        <button type="button" id="mcp-connect-btn" class="mcp-btn">
          <iconify-icon icon="solar:link-bold" width="16"></iconify-icon>
          Connect an assistant
        </button>
      </div>
    </div>

    <!-- Pairing panel (injected here via AJAX) -->
    <div id="mcp-panel-slot" style="margin-bottom:1rem;"></div>

    <!-- Connected assistants + API keys -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;">
      <!-- Connected assistants -->
      <div class="mcp-card">
        <div style="display:flex;align-items:center;gap:8px;">
          <iconify-icon icon="solar:users-group-rounded-bold" width="18" style="color:#2563eb;"></iconify-icon>
          <h3 style="margin:0;font-size:16px;">Connected assistants</h3>
        </div>
        <p style="margin:2px 0 12px;font-size:13px;color:#64748b;">These assistants have read-only access to your reports.</p>

        <?php if ($assistants === []): ?>
          <div class="mcp-empty">
            <p>Waiting for connection&hellip;</p>
            <p>Finish the steps above and your assistant will show up here.</p>
          </div>
        <?php else: ?>
          <ul style="list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:8px;">
            <?php foreach ($assistants as $a): ?>
              <li style="display:flex;align-items:center;justify-content:space-between;gap:8px;border:0.5px solid #e2e8f0;border-radius:10px;padding:10px 12px;">
                <div>
                  <p style="margin:0;font-size:14px;font-weight:500;"><?= h(ucfirst($a['client_name'] !== '' ? $a['client_name'] : 'Assistant')) ?></p>
                  <p style="margin:2px 0 0;font-size:12px;color:#64748b;">
                    Added <?= h(substr((string)$a['created_at'], 0, 10)) ?>
                    <?= $a['last_used_at'] !== null ? ' · Last used ' . h(substr((string)$a['last_used_at'], 0, 16)) : '' ?>
                  </p>
                </div>
                <form method="post" onsubmit="return confirm('Disconnect this assistant? It will lose access immediately.');">
                  <input type="hidden" name="disconnect_id" value="<?= (int)$a['id'] ?>">
                  <button type="submit" class="mcp-del">Disconnect</button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <!-- API keys -->
      <div class="mcp-card">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;">
          <div style="display:flex;align-items:center;gap:8px;">
            <iconify-icon icon="solar:key-square-2-bold" width="18" style="color:#2563eb;"></iconify-icon>
            <h3 style="margin:0;font-size:16px;">API keys</h3>
          </div>
          <form method="post" style="margin:0;">
            <button type="submit" name="create_key" value="1" class="mcp-btn" style="padding:5px 10px;font-size:12px;">
              <iconify-icon icon="solar:add-circle-bold" width="14"></iconify-icon>
              Create API key
            </button>
          </form>
        </div>
        <p style="margin:0 0 12px;font-size:13px;color:#64748b;">Use an API key for assistants that don't support one-click connection.</p>

        <?php if ($apiKeys === []): ?>
          <div class="mcp-empty">
            <p>No API keys yet</p>
            <p>Create a key to give an assistant read-only access. Keep it private, like a password.</p>
          </div>
        <?php else: ?>
          <ul style="list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:8px;">
            <?php foreach ($apiKeys as $k):
              $active = ($k['status'] ?? '') === 'active';
              $used = (int)($k['total_calls'] ?? 0) > 0;
              if (!$active) { $badge = ['Deleted', 'mcp-badge-deleted']; }
              elseif ($used) { $badge = ['Connected', 'mcp-badge-connected']; }
              else { $badge = ['Created', 'mcp-badge-created']; }
            ?>
              <li style="display:flex;align-items:center;justify-content:space-between;gap:8px;border:0.5px solid #e2e8f0;border-radius:10px;padding:10px 12px;">
                <div>
                  <p style="margin:0;font-size:14px;font-weight:500;">Key ····<?= h($k['key_hint']) ?>
                    <span class="<?= $badge[1] ?>"><?= $badge[0] ?></span>
                  </p>
                  <p style="margin:2px 0 0;font-size:12px;color:#64748b;">
                    Created <?= h(substr((string)$k['created_at'], 0, 10)) ?>
                    <?= $k['last_used_at'] !== null ? ' · Last used ' . h(substr((string)$k['last_used_at'], 0, 16)) : ' · Never used' ?>
                    · <?= (int)($k['total_calls'] ?? 0) ?> calls
                  </p>
                </div>
                <?php if ($active): ?>
                  <form method="post" onsubmit="return confirm('Delete this API key? Anyone using it will lose MCP access immediately.');">
                    <input type="hidden" name="revoke_key_id" value="<?= (int)$k['id'] ?>">
                    <button type="submit" class="mcp-del">Delete</button>
                  </form>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <p style="margin:1rem 0 0;font-size:12px;color:#94a3b8;display:flex;align-items:center;gap:4px;">
      <iconify-icon icon="solar:lock-bold" width="13"></iconify-icon>
      Read-only access · Secure connection · Revoke access anytime
    </p>
  <?php endif; ?>
</div>

<script>
(function () {
  var btn = document.getElementById('mcp-connect-btn');
  if (!btn) return;
  var slot = document.getElementById('mcp-panel-slot');
  var mcpUrl = <?= json_encode(MCP_SERVER_URL . '/mcp') ?>;

  var clients = {
    claude: {
      name: 'Claude',
      logo: brandSvg.claude || '',
      sub: 'Follow these steps in Claude. It takes about a minute.',
      steps: ['In Claude, go to Settings › Connectors and click Add custom connector.',
              'Name it Search Console, paste the server URL below, and click Add.',
              'Click Connect, enter your pairing code, and click Allow.'],
      done: 'Once connected, Claude will appear under Connected assistants below.'
    },
    chatgpt: {
      name: 'ChatGPT',
      logo: brandSvg.chatgpt || '',
      sub: 'Follow these steps in ChatGPT. Developer mode needs to be turned on.',
      steps: ['In ChatGPT, go to Settings › Connectors and turn on Developer mode.',
              'Add a new connector, name it Search Console, and paste the server URL below.',
              'When asked to sign in, enter your pairing code and approve access.'],
      done: 'Once connected, ChatGPT will appear under Connected assistants below.'
    }
  };

  function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;'); }

  function selectClient(key) {
    var c = clients[key];
    document.querySelectorAll('#mcp-tabs .mcp-tab').forEach(function (t) {
      var on = t.dataset.c === key;
      t.classList.toggle('on', on);
    });
    var logo = document.getElementById('mcp-step-logo');
    logo.style.background = c.bg;
    logo.innerHTML = c.logo ? c.logo.replace('<svg ', '<svg width="20" height="20" fill="' + c.fg + '" ') : esc(c.name.charAt(0));
    document.getElementById('mcp-step-name').textContent = 'Connect ' + c.name;
    document.getElementById('mcp-step-sub').textContent = c.sub;
    var steps = '';
    c.steps.forEach(function (s, i) {
      steps += '<div class="mcp-step"><span class="num">' + (i + 1) + '</span><span>' + esc(s) + '</span></div>';
      if (i === 1) {
        steps += '<div class="mcp-step"><span class="num" style="visibility:hidden">2</span><div style="flex:1;min-width:0;">' +
          '<div class="mcp-url"><code>' + esc(mcpUrl) + '</code>' +
          '<button type="button" data-copy-text="' + esc(mcpUrl) + '" aria-label="Copy URL"><iconify-icon icon="solar:copy-outline"></iconify-icon></button></div></div></div>';
      }
    });
    document.getElementById('mcp-steps').innerHTML = steps;
    var doneEl = document.getElementById('mcp-done-note');
    if (doneEl) doneEl.textContent = c.done;
  }

  function wirePanel() {
    document.querySelectorAll('#mcp-tabs .mcp-tab').forEach(function (t) {
      t.addEventListener('click', function () { selectClient(t.dataset.c); });
    });
    document.querySelectorAll('[data-copy-text]').forEach(function (b) {
      b.addEventListener('click', function () {
        var icon = b.querySelector('i, iconify-icon');
        navigator.clipboard.writeText(b.dataset.copyText).then(function () {
          if (icon) { icon.setAttribute('icon', 'solar:check-circle-bold'); icon.style.color = '#16a34a'; }
          setTimeout(function () { if (icon) { icon.setAttribute('icon', 'solar:copy-outline'); icon.style.color = ''; } }, 1500);
        });
      });
    });

    var left = parseInt((document.getElementById('mcp-countdown') || {}).dataset ? (document.getElementById('mcp-countdown').dataset.left || '600') : '600', 10);
    var el = document.getElementById('mcp-countdown');
    var codeEl = document.getElementById('mcp-code');
    var noteEl = document.getElementById('mcp-exp-note');
    var timer = setInterval(function () {
      left--;
      if (left <= 0) {
        clearInterval(timer);
        if (el) el.textContent = '0:00';
        if (codeEl) codeEl.style.opacity = '0.35';
        if (noteEl) noteEl.textContent = 'This code expired. Generate a new one to continue.';
        return;
      }
      if (el) el.textContent = Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0');
    }, 1000);
  }

  function buildPanel(code, expiresIn) {
    slot.innerHTML =
      '<div class="mcp-card">' +
        '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;">' +
          '<h2 style="margin:0;font-size:16px;">Your pairing code</h2>' +
          '<span style="font-size:12px;color:#64748b;"><iconify-icon icon="solar:clock-circle-outline" width="14" style="vertical-align:-2px;"></iconify-icon> Expires in <span id="mcp-countdown" data-left="' + (expiresIn || 600) + '" style="color:#2563eb;font-weight:500;">10:00</span></span>' +
        '</div>' +
        '<div class="mcp-code-row">' +
          '<span id="mcp-code" class="code">' + esc(code) + '</span>' +
          '<button type="button" id="mcp-copy-code" aria-label="Copy code" class="cp"><iconify-icon icon="solar:copy-outline"></iconify-icon></button>' +
        '</div>' +
        '<p id="mcp-exp-note" style="margin:6px 0 16px;font-size:12px;color:#94a3b8;">Single use. Generate a new code if it expires.</p>' +
        '<p style="margin:0 0 8px;font-size:13px;font-weight:500;">Choose your assistant</p>' +
        '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;" id="mcp-tabs">' +
          '<button type="button" class="mcp-tab on" data-c="claude">Claude</button>' +
          '<button type="button" class="mcp-tab" data-c="chatgpt">ChatGPT</button>' +
        '</div>' +
        '<div style="border:0.5px solid #e2e8f0;border-radius:10px;padding:1rem;">' +
          '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">' +
            '<div id="mcp-step-logo" class="w-9 h-9 rounded-full flex items-center justify-center" style="background:#fdece4;width:36px;height:36px;"></div>' +
            '<div><p id="mcp-step-name" style="margin:0;font-size:14px;font-weight:500;">Connect Claude</p>' +
            '<p id="mcp-step-sub" style="margin:0;font-size:12px;color:#64748b;">Follow these steps in Claude. It takes about a minute.</p></div>' +
          '</div>' +
          '<div id="mcp-steps"></div>' +
          '<p style="margin:12px 0 0;padding-top:10px;border-top:0.5px solid #e2e8f0;font-size:12px;color:#64748b;">' +
            '<iconify-icon icon="solar:check-circle-bold" width="14" style="color:#16a34a;vertical-align:-2px;margin-right:4px;"></iconify-icon>' +
            '<span id="mcp-done-note">Once connected, Claude will appear under Connected assistants below.</span></p>' +
        '</div>' +
      '</div>';

    // merge the static clients (from earlier script scope) — rebuild simple steps
    var panelClients = {
      claude: {
        name: 'Claude', logoBg: '#fdece4', logoFg: '#d97757', logoText: 'A\\',
        steps: ['In Claude, go to Settings › Connectors and click Add custom connector.',
                'Name it Search Console, paste the server URL below, and click Add.',
                'Click Connect, enter your pairing code, and click Allow.'],
        done: 'Once connected, Claude will appear under Connected assistants below.'
      },
      chatgpt: {
        name: 'ChatGPT', logoBg: '#eef2f6', logoFg: '#111111', logoText: 'G',
        steps: ['In ChatGPT, go to Settings › Connectors and turn on Developer mode.',
                'Add a new connector, name it Search Console, and paste the server URL below.',
                'When asked to sign in, enter your pairing code and approve access.'],
        done: 'Once connected, ChatGPT will appear under Connected assistants below.'
      }
    };
    var mcpUrl = mcpUrl;

    function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;'); }
    function selectClient(key) {
      var c = panelClients[key];
      document.querySelectorAll('#mcp-tabs .mcp-tab').forEach(function (t) {
        t.classList.toggle('on', t.dataset.c === key);
      });
      var logo = document.getElementById('mcp-step-logo');
      logo.textContent = c.logoText;
      logo.style.background = c.logoBg;
      logo.style.color = c.logoFg;
      document.getElementById('mcp-step-name').textContent = c.name;
      document.getElementById('mcp-step-sub').textContent = c.sub;
      var stepsHtml = '';
      c.steps.forEach(function (s, i) {
        stepsHtml += '<div class="mcp-step"><span class="num">' + (i + 1) + '</span><span>' + esc(s) + '</span></div>';
        if (i === 1) {
          stepsHtml += '<div class="mcp-step"><span class="num" style="visibility:hidden">' + (i + 1) + '</span>' +
            '<div style="flex:1;min-width:0;"><div class="mcp-url"><code>' + esc(mcpUrl) + '</code>' +
            '<button type="button" data-copy-url="1" aria-label="Copy URL"><iconify-icon icon="solar:copy-outline"></iconify-icon></button></div></div></div>';
        }
      });
      document.getElementById('mcp-steps').innerHTML = stepsHtml;
      document.querySelectorAll('#mcp-steps [data-copy-url]').forEach(function (b) {
        b.addEventListener('click', function () {
          navigator.clipboard.writeText(mcpUrl).then(function () {
            b.querySelector('iconify-icon').setAttribute('icon', 'solar:check-circle-bold');
            setTimeout(function () { b.querySelector('iconify-icon').setAttribute('icon', 'solar:copy-outline'); }, 1500);
          });
        });
      });
    }
    document.querySelectorAll('#mcp-tabs .mcp-tab').forEach(function (t) {
      t.addEventListener('click', function () { selectClient(t.dataset.c); });
    });
    selectClient('claude');

    var left = expiresIn || 600;
    var el = document.getElementById('mcp-countdown');
    var codeEl = document.getElementById('mcp-code');
    var noteEl = document.getElementById('mcp-exp-note');
    var timer = setInterval(function () {
      left--;
      if (left <= 0) {
        clearInterval(timer);
        if (el) el.textContent = '0:00';
        if (codeEl) codeEl.style.opacity = '0.35';
        if (noteEl) noteEl.textContent = 'This code expired. Generate a new one to continue.';
        return;
      }
      if (el) el.textContent = Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0');
    }, 1000);

    var copyBtn = document.getElementById('mcp-copy-code');
    if (copyBtn) {
      copyBtn.addEventListener('click', function () {
        navigator.clipboard.writeText(code).then(function () {
          var i = copyBtn.querySelector('iconify-icon');
          i.setAttribute('icon', 'solar:check-circle-bold');
          setTimeout(function () { i.setAttribute('icon', 'solar:copy-outline'); }, 1500);
        });
      });
    }

    slot.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  btn.addEventListener('click', function () {
    if (btn.dataset.loading === '1') return;
    btn.dataset.loading = '1';
    var old = btn.innerHTML;
    btn.innerHTML = 'Generating code…';
    fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'ajax_generate=1'
    }).then(function (r) { return r.text(); }).then(function (t) {
      var d = null;
      try { d = JSON.parse(t); } catch (e) {
        var m = t.match(/\{[\s\S]*"ok"[\s\S]*\}/);
        if (m) { try { d = JSON.parse(m[0]); } catch (e2) {} }
      }
      btn.dataset.loading = '';
      btn.innerHTML = old;
      if (!d || !d.ok) { alert((d && d.error) ? d.error : 'Could not create a pairing code right now.'); return; }
      buildPanel(d.code, d.expires_in_seconds);
    }).catch(function () {
      btn.dataset.loading = '';
      btn.innerHTML = old;
      alert('Could not create a pairing code right now.');
    });
  });
})();
</script>

<?php include './partials/layouts/layoutBottom.php'; ?>
