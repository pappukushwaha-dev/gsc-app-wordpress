<?php
/**
 * Orgalytics GSC MCP connector settings (gsc-app-wordpress app).
 * The secret must match MCP_INTERNAL_SECRET in the MCP server's .env.
 */
define('MCP_SERVER_URL', 'https://mcp.orgalytics.ai');
define('MCP_INTERNAL_FALLBACK_URL', 'https://127.0.0.1');

// This app's identity on the MCP server
define('MCP_PLATFORM', 'wordpress');
define('MCP_GA_COLUMN', 'instance_id');   // google_accounts store column

define('MCP_INTERNAL_SECRET', 'CHANGE_ME_PASTE_SECRET_HERE');
