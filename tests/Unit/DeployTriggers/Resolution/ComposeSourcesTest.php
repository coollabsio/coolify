<?php

use App\Services\DeployTriggers\Resolution\ComposeSources;

it('collects build contexts, dockerfiles, bind mounts, env files and config files', function () {
    $sources = ComposeSources::parse(<<<'YAML'
        services:
          api:
            build: ./api
            env_file: .env.api
            volumes:
              - ./config/api.yml:/etc/api.yml:ro
              - data:/data
              - /var/run/docker.sock:/var/run/docker.sock
          web:
            build:
              context: ../shared/web
              dockerfile: docker/Dockerfile.prod
              additional_contexts:
                assets: ./assets
                base: docker-image://node:22
            env_file:
              - path: .env.web
          db:
            image: postgres:16
        configs:
          nginx:
            file: ./nginx.conf
        secrets:
          key:
            environment: KEY
        YAML, 'deploy/docker-compose.yml');

    // A bind mount may be a directory, so it is a directory rule (which also matches the exact path).
    expect($sources)->toBe([
        'dirs' => ['deploy/api', 'deploy/config/api.yml', 'shared/web', 'deploy/assets'],
        'files' => ['deploy/docker-compose.yml', 'deploy/.env.api', 'shared/web/docker/Dockerfile.prod', 'deploy/.env.web', 'deploy/nginx.conf'],
    ]);
});

it('gives up on compose files it cannot bound', function (string $yaml) {
    expect(ComposeSources::parse($yaml, 'docker-compose.yml'))->toBeNull();
})->with([
    'include' => ["include:\n  - other.yml\nservices: {}"],
    'extends' => ["services:\n  a:\n    extends:\n      file: base.yml\n      service: b"],
    'remote context' => ["services:\n  a:\n    build: https://github.com/acme/app.git"],
    'context outside the repository' => ["services:\n  a:\n    build: ../../x"],
    'invalid yaml' => ["services: [\n"],
]);
