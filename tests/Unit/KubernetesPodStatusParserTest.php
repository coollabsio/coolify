<?php

use App\Services\Kubernetes\KubernetesPodStatusParser;
use Illuminate\Support\Carbon;

it('parses kubectl pod json into destination pod rows', function () {
    Carbon::setTestNow('2026-05-19T12:00:00Z');

    $json = json_encode([
        'items' => [
            [
                'metadata' => [
                    'name' => 'customer-api-7f8d9c6b5d-r4ndm',
                    'namespace' => 'production',
                    'creationTimestamp' => '2026-05-19T11:50:00Z',
                    'labels' => [
                        'coolify.io/application-uuid' => 'app-uuid',
                    ],
                ],
                'spec' => [
                    'nodeName' => 'worker-1',
                    'containers' => [
                        ['name' => 'application'],
                    ],
                ],
                'status' => [
                    'phase' => 'Running',
                    'containerStatuses' => [
                        [
                            'name' => 'application',
                            'ready' => true,
                            'restartCount' => 2,
                        ],
                    ],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $pods = (new KubernetesPodStatusParser)->parse($json);

    expect($pods)->toHaveCount(1);
    expect($pods[0])->toMatchArray([
        'name' => 'customer-api-7f8d9c6b5d-r4ndm',
        'namespace' => 'production',
        'phase' => 'Running',
        'ready' => '1/1',
        'restarts' => 2,
        'node' => 'worker-1',
        'containers' => 'application',
        'container_names' => ['application'],
        'application_uuid' => 'app-uuid',
    ]);
    expect($pods[0]['age'])->toBe('10m ago');
});
