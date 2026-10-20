<?php
/**
 * Orgalytics GSC MCP connector settings (Ecwid app).
 * The secret must match MCP_INTERNAL_SECRET in the MCP server's .env.
 */
define('MCP_SERVER_URL', 'https://mcp.orgalytics.ai');

// Fallback origin for server-side API calls when Cloudflare blocks the loop
// through the public URL. The hostname is DNS-pinned to this origin IP
// (CURLOPT_RESOLVE) so SNI/Host/cert all stay correct. Leave '' to disable.
define('MCP_INTERNAL_FALLBACK_URL', 'https://127.0.0.1');

// This app's identity on the MCP server
define('MCP_PLATFORM', 'ecwid');
define('MCP_GA_COLUMN', 'instance_id');   // google_accounts store column (wix = instanceId)

define('MCP_INTERNAL_SECRET', 'CHANGE_ME_PASTE_SECRET_HERE');
