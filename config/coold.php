<?php

return [
    'coolify_cli_bin' => env('COOLIFY_CLI_BIN', '/usr/local/bin/coolify'),
    'coold_version' => env('COOLIFY_COOLD_VERSION', 'nightly'),
    'corrosion_version' => env('COOLIFY_CORROSION_VERSION', 'v1.0.0'),
    'dev_ssh_user' => env('COOLIFY_CLI_SSH_USER', 'coolify'),
    'flux_url' => env('COOLIFY_COOLD_FLUX_URL', env('COOLIFY_COOLD_VM_FLUX_URL')),
    'flux_host_jwt_path' => env('COOLIFY_COOLD_HOST_JWT_PATH', '/etc/coolify/host-jwt'),

    /*
     * When false (the default), v5 server hosts/node addresses may not point at
     * private or reserved IP ranges (loopback, link-local, RFC 1918, CGNAT is
     * still allowed as it is the WireGuard mesh space). This blocks a team
     * member from adding a server that targets the Coolify host's internal
     * network and abusing the synchronous SSH connectivity check to probe it.
     *
     * Self-hosters running Coolify on a private LAN can opt back in by setting
     * COOLIFY_ALLOW_PRIVATE_SERVER_IPS=true.
     */
    'allow_private_server_ips' => filter_var(
        env('COOLIFY_ALLOW_PRIVATE_SERVER_IPS', false),
        FILTER_VALIDATE_BOOLEAN
    ),
];
