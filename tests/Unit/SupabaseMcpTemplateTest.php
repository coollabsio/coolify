<?php

it('keeps Supabase MCP blocked by default and configurable for private access', function () {
    $template = file_get_contents(__DIR__.'/../../templates/compose/supabase.yaml');

    expect($template)
        ->toContain('SUPABASE_MCP_ENABLED=${SUPABASE_MCP_ENABLED:-false}')
        ->toContain('SUPABASE_MCP_IP_ALLOWLIST=${SUPABASE_MCP_IP_ALLOWLIST:-127.0.0.1,::1}')
        ->toContain('export SUPABASE_MCP_PLUGINS=')
        ->toContain('url: http://supabase-studio:3000/api/mcp')
        ->toContain('message: "Supabase MCP access is disabled."')
        ->toContain('SUPABASE_MCP_IP_ALLOWLIST controls access from VPN, WireGuard, or SSH tunnel client IPs.');
});
