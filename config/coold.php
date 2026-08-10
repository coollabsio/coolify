<?php

return [
    'coolify_cli_bin' => env('COOLIFY_CLI_BIN', '/usr/local/bin/coolify'),
    'coold_version' => env('COOLIFY_COOLD_VERSION', 'nightly'),
    'corrosion_version' => env('COOLIFY_CORROSION_VERSION', 'v1.0.0'),
    'dev_ssh_user' => env('COOLIFY_CLI_SSH_USER', 'coolify'),
    'flux_url' => env('COOLIFY_COOLD_FLUX_URL', env('COOLIFY_COOLD_VM_FLUX_URL')),
    'flux_host_jwt_path' => env('COOLIFY_COOLD_HOST_JWT_PATH', '/etc/coolify/host-jwt'),
];
