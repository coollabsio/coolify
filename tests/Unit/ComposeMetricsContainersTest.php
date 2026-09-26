<?php

function composeMetricsDockerPsRow(string $name, string $labels, string $state = 'running'): array
{
    return ['Names' => $name, 'Labels' => $labels, 'State' => $state];
}

it('maps running compose containers to the names sentinel records their metrics under', function () {
    $containers = collect([
        composeMetricsDockerPsRow('worker-app-uuid-20260101T120000', 'coolify.applicationId=1,com.docker.compose.service=worker,coolify.name=worker-app-uuid'),
        composeMetricsDockerPsRow('api-app-uuid-20260101T120000', 'traefik.http.routers.api.rule=Host(`a.example.com`,`b.example.com`),com.docker.compose.service=api,coolify.name=api-app-uuid'),
    ]);

    expect(mapComposeContainersToMetricsNames($containers))->toBe([
        'api-app-uuid' => 'api',
        'worker-app-uuid' => 'worker',
    ]);
});

it('skips compose containers that are not running', function () {
    $containers = collect([
        composeMetricsDockerPsRow('api-app-uuid', 'com.docker.compose.service=api,coolify.name=api-app-uuid'),
        composeMetricsDockerPsRow('migrate-app-uuid', 'com.docker.compose.service=migrate,coolify.name=migrate-app-uuid', 'exited'),
    ]);

    expect(mapComposeContainersToMetricsNames($containers))->toBe(['api-app-uuid' => 'api']);
});

it('falls back to the container name when the coolify.name label is missing', function () {
    $containers = collect([
        composeMetricsDockerPsRow('myproject-web-1', 'coolify.applicationId=1,com.docker.compose.service=web'),
    ]);

    expect(mapComposeContainersToMetricsNames($containers))->toBe(['myproject-web-1' => 'web']);
});

it('skips containers whose metrics name is not a valid container name', function () {
    $containers = collect([
        composeMetricsDockerPsRow('api-app-uuid', 'com.docker.compose.service=api,coolify.name=api$(whoami)'),
        composeMetricsDockerPsRow('web-app-uuid', 'com.docker.compose.service=web,coolify.name=web-app-uuid'),
    ]);

    expect(mapComposeContainersToMetricsNames($containers))->toBe(['web-app-uuid' => 'web']);
});

it('labels replicas of the same compose service by container name', function () {
    $containers = collect([
        composeMetricsDockerPsRow('myproject-worker-2', 'com.docker.compose.service=worker'),
        composeMetricsDockerPsRow('myproject-worker-1', 'com.docker.compose.service=worker'),
        composeMetricsDockerPsRow('myproject-web-1', 'com.docker.compose.service=web'),
    ]);

    expect(mapComposeContainersToMetricsNames($containers))->toBe([
        'myproject-worker-1' => 'myproject-worker-1',
        'myproject-worker-2' => 'myproject-worker-2',
        'myproject-web-1' => 'web',
    ]);
});

it('returns no metrics containers when nothing is running', function () {
    expect(mapComposeContainersToMetricsNames(collect()))->toBe([]);
});
