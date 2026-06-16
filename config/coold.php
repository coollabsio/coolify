<?php

return [
    'coolify_cli_bin' => env('COOLIFY_CLI_BIN', '/usr/local/bin/coolify'),
    'coold_version' => env('COOLIFY_COOLD_VERSION', 'nightly'),
    'corrosion_version' => env('COOLIFY_CORROSION_VERSION', 'v1.0.0'),
    'dev_builder_capacity' => (int) env('COOLIFY_COOLD_VM_BUILDER_CAPACITY', 2),
    'dev_builder_enabled' => (int) env('COOLIFY_COOLD_VM_BUILDER_CAPACITY', 2) > 0,
];
