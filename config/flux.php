<?php

return [
    'unix_socket_path' => env('COOLIFY_FLUX_UNIX_SOCKET_PATH', '/run/coolify/flux.sock'),
    'jwt_private_key_path' => env('COOLIFY_FLUX_JWT_PRIVATE_KEY_PATH', storage_path('app/flux/jwt.priv')),
    'jwt_public_key_path' => env('COOLIFY_FLUX_JWT_PUBLIC_KEY_PATH', storage_path('app/flux/jwt.pub')),
    'health_timeout_seconds' => (float) env('COOLIFY_FLUX_HEALTH_TIMEOUT_SECONDS', 1.0),
];
