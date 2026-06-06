<?php

use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\NetworkAttachment;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Services\Docker\NetworkAttachableResolver;
use Illuminate\Support\Collection;

it('resolves resource type for application and service', function () {
    $resolver = app(NetworkAttachableResolver::class);

    expect($resolver->resolveResourceType(new Application))->toBe('application')
        ->and($resolver->resolveResourceType(new Service))->toBe('service');
});

it('resolves server from application destination and service server relation', function () {
    $server = new Server(['id' => 10]);
    $destination = new StandaloneDocker;
    $destination->setRelation('server', $server);

    $application = new Application;
    $application->setRelation('destination', $destination);

    $service = new Service;
    $service->setRelation('server', $server);

    $resolver = app(NetworkAttachableResolver::class);

    expect($resolver->resolveServer($application))->toBe($server)
        ->and($resolver->resolveServer($service))->toBe($server);
});

it('resolves application runtime container from coolify labels', function () {
    $server = fakeServerWithContainers([
        [
            'ID' => 'container-1',
            'Names' => 'api-container',
            'Labels' => 'coolify.applicationId=10,coolify.type=application,com.docker.compose.service=api',
        ],
        [
            'ID' => 'container-2',
            'Names' => 'preview-container',
            'Labels' => 'coolify.applicationId=10,coolify.pullRequestId=123,coolify.type=application',
        ],
    ]);
    $application = fakeApplication($server, 10);

    expect(app(NetworkAttachableResolver::class)->resolveRuntimeContainer($application))->toBe([
        'id' => 'container-1',
        'name' => 'api-container',
    ]);
});

it('reuses cached container id while runtime container still exists', function () {
    $server = fakeServerWithContainers([
        [
            'ID' => 'current-id',
            'Names' => 'api-container',
            'Labels' => 'coolify.applicationId=10,coolify.type=application',
        ],
    ]);
    $application = fakeApplication($server, 10);
    $attachment = new NetworkAttachment(['container_id' => 'current-id', 'container_name' => 'old-name']);

    expect(app(NetworkAttachableResolver::class)->resolveRuntimeContainer($application, $attachment))->toBe([
        'id' => 'current-id',
        'name' => 'api-container',
    ]);
});

it('matches container by name when container id changed', function () {
    $server = fakeServerWithContainers([
        [
            'ID' => 'new-id',
            'Names' => 'fixed-app-name',
            'Labels' => 'coolify.applicationId=10,coolify.type=application',
        ],
    ]);
    $application = fakeApplication($server, 10, [
        'custom_internal_name' => 'fixed-app-name',
        'is_consistent_container_name_enabled' => false,
    ]);
    $attachment = new NetworkAttachment(['container_id' => 'old-id', 'container_name' => 'fixed-app-name']);

    expect(app(NetworkAttachableResolver::class)->resolveRuntimeContainer($application, $attachment))->toBe([
        'id' => 'new-id',
        'name' => 'fixed-app-name',
    ]);
});

it('supports consistent container names using application uuid', function () {
    $server = fakeServerWithContainers([
        [
            'ID' => 'uuid-id',
            'Names' => 'app-uuid',
            'Labels' => 'coolify.applicationId=10,coolify.type=application',
        ],
    ]);
    $application = fakeApplication($server, 10, [
        'custom_internal_name' => null,
        'is_consistent_container_name_enabled' => true,
    ], 'app-uuid');

    expect(app(NetworkAttachableResolver::class)->resolveRuntimeContainer($application))->toBe([
        'id' => 'uuid-id',
        'name' => 'app-uuid',
    ]);
});

it('resolves service container from docker labels', function () {
    $server = fakeServerWithContainers([
        [
            'ID' => 'db-id',
            'Names' => '/db-container',
            'Labels' => 'coolify.serviceId=30,coolify.type=service,com.docker.compose.service=db',
        ],
        [
            'ID' => 'other-id',
            'Names' => 'other-container',
            'Labels' => 'coolify.serviceId=31,coolify.type=service',
        ],
    ]);

    $service = new Service;
    $service->id = 30;
    $service->setRelation('server', $server);

    expect(app(NetworkAttachableResolver::class)->resolveRuntimeContainer($service))->toBe([
        'id' => 'db-id',
        'name' => 'db-container',
    ]);
});

function fakeServerWithContainers(array $containers): Server
{
    return new class($containers) extends Server
    {
        public function __construct(private array $containers) {}

        public function loadAllContainers(): Collection
        {
            return collect($this->containers);
        }
    };
}

function fakeApplication(Server $server, int $id, array $settings = [], string $uuid = 'application-uuid'): Application
{
    $destination = new StandaloneDocker;
    $destination->setRelation('server', $server);

    $application = new Application;
    $application->id = $id;
    $application->uuid = $uuid;
    $application->setRelation('destination', $destination);
    $application->setRelation('settings', new ApplicationSetting(array_merge([
        'custom_internal_name' => null,
        'is_consistent_container_name_enabled' => false,
    ], $settings)));

    return $application;
}
