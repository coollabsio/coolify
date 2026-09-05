<?php

it('ships the Appwrite 2.0 one-click service template', function () {
    $compose = file_get_contents(__DIR__.'/../../templates/compose/appwrite.yaml');

    expect($compose)
        ->toContain('image: appwrite/appwrite:2.0.0')
        ->toContain('image: appwrite/new:1.1.16')
        ->toContain('image: appwrite/postgres:0.1.0')
        ->toContain('image: clickhouse/clickhouse-server:26.4.3-alpine')
        ->toContain('image: appwrite/geo:0.3.1')
        ->toContain('image: openruntimes/executor:0.29.0')
        ->toContain('image: ghcr.io/open-runtimes/orchestrator/orchestrator:1.9.2')
        ->not->toContain('mongo:')
        ->not->toContain('mariadb');

    // Console IV is served at the root path, the API under /v1 and realtime under /v1/realtime.
    expect($compose)
        ->toContain('SERVICE_URL_APPWRITE=/v1')
        ->toContain('SERVICE_URL_APPWRITE=/v1/realtime')
        ->toContain('SERVICE_URL_APPWRITE_3000=/')
        ->toContain('_APP_CONSOLE_URL_SCHEME=root')
        ->toContain('APPWRITE_ENDPOINT_SAME_ORIGIN=true');

    // PostgreSQL is the primary database; DocumentsDB, VectorsDB and embeddings stay off like upstream.
    expect($compose)
        ->toContain('_APP_DB_ADAPTER=${_APP_DB_ADAPTER:-postgresql}')
        ->toContain('_APP_DB_HOST=${_APP_DB_HOST:-appwrite-postgresql}')
        ->toContain('_APP_DOCUMENTSDB=${_APP_DOCUMENTSDB:-disabled}')
        ->toContain('_APP_VECTORSDB=${_APP_VECTORSDB:-disabled}')
        ->toContain('_APP_EMBEDDING=${_APP_EMBEDDING:-disabled}');

    // Coolify prefixes named volumes and the service network with the service UUID, which
    // Compose exposes as COMPOSE_PROJECT_NAME. The orchestrator must use those real names.
    expect($compose)
        ->toContain('_APP_BUILDS_VOLUME=${COMPOSE_PROJECT_NAME}_appwrite-builds')
        ->toContain('ORCHESTRATOR_NETWORK=${COMPOSE_PROJECT_NAME}')
        ->toContain('DOCKER_NETWORK=${COMPOSE_PROJECT_NAME}');

    // Secrets are generated per deployment instead of shipping upstream placeholders.
    expect($compose)
        ->toContain('_APP_OPENSSL_KEY_V1=$SERVICE_PASSWORD_64_APPWRITE')
        ->toContain('_APP_EXECUTOR_SECRET=$SERVICE_PASSWORD_64_EXECUTOR')
        ->toContain('_APP_JOBS_SECRET=$SERVICE_PASSWORD_64_JOBS')
        ->toContain('_APP_GEO_SECRET=$SERVICE_PASSWORD_64_GEO')
        ->toContain('_APP_NOTIFICATIONS_TRACKING_SECRET=$SERVICE_PASSWORD_64_NOTIFICATIONS')
        ->toContain('_APP_CONNECTIONS_DB_USAGE=http://appwrite:$SERVICE_PASSWORD_CLICKHOUSE@appwrite-clickhouse:8123/appwrite')
        ->not->toContain('your-secret-key');

    // Coolify terminates TLS, so Appwrite must not try to issue certificates itself.
    expect($compose)
        ->toContain('_APP_ROUTER_AUTO_CERTIFICATES=${_APP_ROUTER_AUTO_CERTIFICATES:-disabled}');
});
