<?php

return [
    'coolify_cli_bin' => env('COOLIFY_CLI_BIN', '/usr/local/bin/coolify'),
    'dev_host_count' => (int) env('COOLIFY_COOLD_VM_COUNT', 2),
    'dev_host_id' => env('COOLIFY_COOLD_DEV_HOST_ID', 'coold-dev'),
    'dev_host_id_2' => env('COOLIFY_COOLD_LIMA_INSTANCE_2', env('COOLIFY_COOLD_DEV_HOST_ID', 'coold-dev').'-2'),
    'dev_wireguard_ip_1' => env('COOLIFY_COOLD_VM_WG_IP_1', '100.64.0.1'),
    'dev_wireguard_ip_2' => env('COOLIFY_COOLD_VM_WG_IP_2', '100.64.0.2'),
    'dev_builder_capacity' => (int) env('COOLIFY_COOLD_VM_BUILDER_CAPACITY', 2),
    'dev_builder_enabled' => (int) env('COOLIFY_COOLD_VM_BUILDER_CAPACITY', 2) > 0,
];
