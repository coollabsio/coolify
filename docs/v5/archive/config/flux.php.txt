<?php

return [
    'unix_socket_path' => env('COOLIFY_FLUX_UNIX_SOCKET_PATH', '/run/coolify/flux.sock'),
    'jwt_private_key_path' => env('COOLIFY_FLUX_JWT_PRIVATE_KEY_PATH', storage_path('app/flux/jwt.priv')),
    'jwt_public_key_path' => env('COOLIFY_FLUX_JWT_PUBLIC_KEY_PATH', storage_path('app/flux/jwt.pub')),
    'health_timeout_seconds' => (float) env('COOLIFY_FLUX_HEALTH_TIMEOUT_SECONDS', 1.0),
    'connection_timeout_seconds' => (float) env('COOLIFY_FLUX_CONNECTION_TIMEOUT_SECONDS', 1.0),
    'dispatch_timeout_seconds' => (float) env('COOLIFY_FLUX_DISPATCH_TIMEOUT_SECONDS', 35.0),
    'bootstrap_host_connection_timeout_seconds' => (int) env('COOLIFY_FLUX_BOOTSTRAP_HOST_CONNECTION_TIMEOUT_SECONDS', 30),

    /*
    |--------------------------------------------------------------------------
    | Host agent (coold) token capabilities
    |--------------------------------------------------------------------------
    |
    | The EXACT set of primitive capability strings coold advertises to flux on
    | connect (coold/coold/src/grpc/client.rs, `primitive_capabilities`). Minting
    | these explicit strings — instead of the `host-agent:default` wildcard
    | profile — means the token no longer relies on flux's
    | `capability_profile_authorizes_all` bypass. flux INTERSECTS the jwt `caps`
    | with coold's advertised set, so as long as this list matches coold's
    | advertised primitives the host retains exactly the same effective power.
    |
    | SAFETY: keep this list byte-for-byte in sync with coold's
    | `primitive_capabilities()`. A string here that coold does not advertise is
    | silently dropped by flux's intersection; a verb coold needs that is missing
    | here means the host loses that ability.
    |
    | `host.jwt.set` authorizes RPC-delivered host-JWT rotation (the token can
    | authorize its own replacement over the live stream; Laravel is the root of
    | trust that holds the signing key).
    */
    'host_capabilities' => [
        'images.pull',
        'images.list',
        'images.delete',
        'containers.create',
        'containers.start',
        'containers.stop',
        'containers.restart',
        'containers.delete',
        'containers.inspect',
        'containers.list',
        'containers.logs',
        'containers.exec',
        'containers.healthcheck.run',
        'ingress.apply',
        'ingress.stop',
        'firewall.allow',
        'firewall.revoke',
        'firewall.list',
        'firewall.reconcile',
        'coold.logs',
        'corrosion.tables',
        'host.jwt.set',
    ],

    /*
    | Emergency escape hatch: when set (e.g. to `host-agent:default`), minted
    | host tokens carry ONLY this single capability profile instead of the
    | explicit list above. This re-enables flux's wildcard bypass and is meant
    | purely for rollback without a code change if the explicit list ever drifts
    | from coold's advertised set and breaks the data plane. Leave NULL in
    | production so tokens are explicitly scoped.
    */
    'host_capability_profile' => env('COOLIFY_FLUX_HOST_CAPABILITY_PROFILE'),

    /*
    | Host JWT lifetime and rotation.
    |
    | `host_token_ttl` is the token `exp` window (default 1h) — the maximum time
    | a leaked/undetected token stays valid if BOTH rotation and revocation fail.
    | Keep this at or below flux's `COOLIFY_FLUX_MAX_TOKEN_LIFETIME_SECS`
    | default (3600), otherwise flux rejects coold streams at connect.
    | `host_token_refresh_threshold` is the remaining-lifetime below which the
    | rotation job re-mints and re-delivers a fresh token (default 30m).
    | Keep the threshold below the TTL.
    */
    'host_token_ttl' => (int) env('COOLIFY_FLUX_HOST_TOKEN_TTL', 3600),
    'host_token_refresh_threshold' => (int) env('COOLIFY_FLUX_HOST_TOKEN_REFRESH_THRESHOLD', 1800),

    /*
    | JWT header `kid` minted into host tokens. flux selects the verification key
    | by this id (single default key today; a per-cluster keys directory can map
    | `kid = cluster-<id>` to `<kid>.pub` for per-tenant signing keys later).
    */
    'jwt_kid' => env('COOLIFY_FLUX_JWT_KID', 'flux-default'),

    /*
    |--------------------------------------------------------------------------
    | Inbound flux -> Laravel API token(s)
    |--------------------------------------------------------------------------
    |
    | flux authenticates to Laravel's internal status-ingest endpoint with a
    | bearer token. `laravel_api_tokens` accepts SEVERAL tokens at once so an
    | operator can rotate with zero downtime:
    |   1. add the new token alongside the old:
    |        COOLIFY_FLUX_LARAVEL_API_TOKENS=<old>,<new>
    |      then `php artisan config:clear`
    |   2. cut every flux instance over to <new>
    |   3. drop <old> from the list and `config:clear` again
    | Generate tokens with `openssl rand -hex 32`. The single `laravel_api_token`
    | remains as a fallback so existing single-token installs keep working.
    */
    'laravel_api_token' => env('COOLIFY_FLUX_LARAVEL_API_TOKEN'),
    'laravel_api_tokens' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('COOLIFY_FLUX_LARAVEL_API_TOKENS', ''))
    ))),
];
