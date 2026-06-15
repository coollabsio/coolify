<?php

return [
    'unix_socket_path' => env('COOLIFY_FLUX_UNIX_SOCKET_PATH', '/run/coolify/flux.sock'),
    'health_timeout_seconds' => (float) env('COOLIFY_FLUX_HEALTH_TIMEOUT_SECONDS', 1.0),
];
