<?php

return [
    'dev_host_count' => (int) env('COOLIFY_COOLD_VM_COUNT', 2),
    'dev_host_id' => env('COOLIFY_COOLD_DEV_HOST_ID', 'coolify-coold-dev'),
    'dev_host_id_2' => env('COOLIFY_COOLD_LIMA_INSTANCE_2', env('COOLIFY_COOLD_DEV_HOST_ID', 'coolify-coold-dev').'-2'),
    'dev_wireguard_ip_1' => env('COOLIFY_COOLD_VM_WG_IP_1', '100.64.0.10'),
    'dev_wireguard_ip_2' => env('COOLIFY_COOLD_VM_WG_IP_2', '100.64.0.11'),
    'dev_builder_capacity' => (int) env('COOLIFY_COOLD_VM_BUILDER_CAPACITY', 2),
    'dev_builder_enabled' => (int) env('COOLIFY_COOLD_VM_BUILDER_CAPACITY', 2) > 0,
];
