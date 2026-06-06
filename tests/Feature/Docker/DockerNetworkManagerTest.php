<?php

use App\Enums\DockerNetworkDriver;
use App\Enums\DockerNetworkRole;
use App\Enums\DockerNetworkScope;
use App\Enums\DockerNetworkSourceType;
use App\Enums\NetworkAttachmentStatus;
use App\Exceptions\DockerNetworkCreationException;
use App\Exceptions\DockerNetworkDeletionException;
use App\Exceptions\DockerNetworkImportException;
use App\Exceptions\DockerNetworkValidationException;
use App\Models\Application;
use App\Models\DockerNetwork;
use App\Models\Environment;
use App\Models\NetworkAttachment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Services\Docker\DockerNetworkManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\InteractsWithDockerNetworks;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class, InteractsWithDockerNetworks::class);

function dockerNetworkManagerWithExecutor(Closure $executor): DockerNetworkManager
{
    return new DockerNetworkManager(executor: $executor);
}

it('creates a managed bridge network with provided technical name', function () {
    $server = $this->createFunctionalServer();
    $createdName = null;
    $manager = dockerNetworkManagerWithExecutor(function (Server $server, array $command) use (&$createdName) {
        if (str_starts_with($command[0], 'docker network inspect')) {
            return $createdName ? $this->dockerInspectPayload($createdName, ['Internal' => true, 'Attachable' => true]) : null;
        }

        if (str_starts_with($command[0], 'docker network create')) {
            expect($command[0])->not->toContain('--attachable');
            expect($command[0])->toEndWith("'backend-private'");
            $createdName = 'backend-private';

            return $createdName;
        }

        return null;
    });

    $network = $manager->create($server, [
        'display_name' => 'Backend Private',
        'docker_network_name' => 'backend-private',
        'driver' => 'bridge',
        'subnet' => '172.30.10.0/24',
        'gateway' => '172.30.10.1',
        'internal' => true,
    ]);

    expect($network->display_name)->toBe('Backend Private')
        ->and($network->docker_network_name)->toBe('backend-private')
        ->and($network->managed_by_coolify)->toBeTrue()
        ->and($network->external)->toBeFalse()
        ->and($network->is_active)->toBeTrue()
        ->and($network->source_type)->toBe(DockerNetworkSourceType::ManagedCustom)
        ->and($network->network_role)->toBe(DockerNetworkRole::PrivateInternal)
        ->and($network->last_inspect_data)->not->toBeNull();
});

it('validates cidr gateway and subnet conflicts before creating networks', function () {
    $server = $this->createFunctionalServer();
    DockerNetwork::create([
        'server_id' => $server->id,
        'display_name' => 'Existing',
        'docker_network_name' => 'existing-net',
        'subnet' => '172.30.10.0/24',
        'is_active' => true,
    ]);
    $manager = dockerNetworkManagerWithExecutor(fn () => null);

    expect(fn () => $manager->create($server, ['display_name' => 'Invalid', 'docker_network_name' => 'invalid-a', 'driver' => 'bridge', 'subnet' => 'abc']))
        ->toThrow(DockerNetworkValidationException::class);

    expect(fn () => $manager->create($server, ['display_name' => 'Invalid', 'docker_network_name' => 'invalid-b', 'driver' => 'bridge', 'subnet' => '172.30.11.0/24', 'gateway' => '172.30.10.1']))
        ->toThrow(DockerNetworkValidationException::class);

    expect(fn () => $manager->create($server, ['display_name' => 'Invalid', 'docker_network_name' => 'invalid-c', 'driver' => 'bridge', 'subnet' => '172.30.10.128/25']))
        ->toThrow(DockerNetworkValidationException::class);
});

it('does not create a database record if docker create fails', function () {
    $server = $this->createFunctionalServer();
    $manager = dockerNetworkManagerWithExecutor(fn () => null);

    expect(fn () => $manager->create($server, ['display_name' => 'No Runtime', 'docker_network_name' => 'no-runtime', 'driver' => 'bridge']))
        ->toThrow(DockerNetworkCreationException::class);

    expect(DockerNetwork::where('server_id', $server->id)->where('display_name', 'No Runtime')->exists())->toBeFalse();
});

it('imports an existing network and sets correct metadata', function () {
    $server = $this->createFunctionalServer();
    $manager = dockerNetworkManagerWithExecutor(fn () => $this->dockerInspectPayload('external-net'));

    $network = $manager->import($server, 'external-net', 'External Shared');

    expect($network->display_name)->toBe('External Shared')
        ->and($network->docker_network_name)->toBe('external-net')
        ->and($network->managed_by_coolify)->toBeFalse()
        ->and($network->external)->toBeTrue()
        ->and($network->source_type)->toBe(DockerNetworkSourceType::ImportedExternal)
        ->and($network->network_role)->toBe(DockerNetworkRole::ManagedCustom);
});

it('promotes an already cataloged external network when importing it', function () {
    $server = $this->createFunctionalServer();
    $existing = DockerNetwork::create([
        'server_id' => $server->id,
        'display_name' => 'Old Alias',
        'docker_network_name' => 'external-net',
        'managed_by_coolify' => false,
        'external' => true,
        'source_type' => DockerNetworkSourceType::ImportedExternal,
        'network_role' => DockerNetworkRole::SharedExternal,
    ]);
    $manager = dockerNetworkManagerWithExecutor(fn () => $this->dockerInspectPayload('external-net'));

    $network = $manager->import($server, 'external-net', 'New Alias');

    expect($network->display_name)->toBe('New Alias')
        ->and($network->id)->toBe($existing->id)
        ->and($network->managed_by_coolify)->toBeFalse()
        ->and($network->external)->toBeTrue();
});

it('uses the runtime network name from inspect when importing', function () {
    $server = $this->createFunctionalServer();
    $existing = DockerNetwork::create([
        'server_id' => $server->id,
        'display_name' => 'Scanned Alias',
        'docker_network_name' => 'runtime-net',
        'managed_by_coolify' => false,
        'external' => true,
        'source_type' => DockerNetworkSourceType::ImportedExternal,
        'network_role' => DockerNetworkRole::SharedExternal,
    ]);
    $manager = dockerNetworkManagerWithExecutor(fn () => $this->dockerInspectPayload('runtime-net'));

    $network = $manager->import($server, 'requested-net', 'Imported Alias');

    expect($network->id)->toBe($existing->id)
        ->and($network->display_name)->toBe('Imported Alias')
        ->and($network->docker_network_name)->toBe('runtime-net')
        ->and(DockerNetwork::where('server_id', $server->id)->count())->toBe(1);
});

it('fails import when inspect cannot find network', function () {
    $server = $this->createFunctionalServer();
    $manager = dockerNetworkManagerWithExecutor(fn () => null);

    expect(fn () => $manager->import($server, 'missing-net'))
        ->toThrow(DockerNetworkImportException::class);
});

it('blocks importing reserved system networks', function () {
    $server = $this->createFunctionalServer();
    $manager = dockerNetworkManagerWithExecutor(fn () => $this->dockerInspectPayload('host', [
        'Driver' => 'host',
        'IPAM' => ['Config' => []],
    ]));

    expect(fn () => $manager->import($server, 'host'))
        ->toThrow(DockerNetworkImportException::class);

    expect(DockerNetwork::where('server_id', $server->id)->count())->toBe(0);
});

it('renames only display name and trims whitespace', function () {
    $server = $this->createFunctionalServer();
    $network = DockerNetwork::create([
        'server_id' => $server->id,
        'display_name' => 'Old',
        'docker_network_name' => 'managed-net',
        'driver' => 'bridge',
        'subnet' => '172.30.10.0/24',
        'gateway' => '172.30.10.1',
        'managed_by_coolify' => true,
        'external' => false,
    ]);
    $manager = dockerNetworkManagerWithExecutor(fn () => null);

    $renamed = $manager->renameDisplayName($network, ' New Display ');

    expect($renamed->display_name)->toBe('New Display')
        ->and($renamed->docker_network_name)->toBe('managed-net')
        ->and($renamed->driver)->toBe(DockerNetworkDriver::Bridge)
        ->and($renamed->subnet)->toBe('172.30.10.0/24')
        ->and($renamed->gateway)->toBe('172.30.10.1');
});

it('inspect updates metadata without overwriting ownership or display name', function () {
    $server = $this->createFunctionalServer();
    $network = DockerNetwork::create([
        'server_id' => $server->id,
        'display_name' => 'Custom',
        'docker_network_name' => 'managed-net',
        'managed_by_coolify' => true,
        'external' => false,
    ]);
    $manager = dockerNetworkManagerWithExecutor(fn () => $this->dockerInspectPayload('managed-net', [
        'Driver' => 'overlay',
        'Scope' => 'swarm',
        'IPAM' => ['Config' => [
            ['Subnet' => '10.10.0.0/16', 'Gateway' => '10.10.0.1'],
            ['Subnet' => 'fd00:cafe::/64', 'Gateway' => 'fd00:cafe::1', 'IPRange' => 'fd00:cafe::/80'],
        ]],
    ]));

    $manager->inspect($network);
    $network->refresh();

    expect($network->display_name)->toBe('Custom')
        ->and($network->managed_by_coolify)->toBeTrue()
        ->and($network->external)->toBeFalse()
        ->and($network->driver)->toBe(DockerNetworkDriver::Overlay)
        ->and($network->scope)->toBe(DockerNetworkScope::Swarm)
        ->and($network->subnet)->toBe('10.10.0.0/16')
        ->and(data_get($network->last_inspect_data, 'ipam_configs.1.Subnet'))->toBe('fd00:cafe::/64')
        ->and($network->last_inspected_at)->not->toBeNull();
});

it('inspect marks missing runtime network inactive', function () {
    $server = $this->createFunctionalServer();
    $network = DockerNetwork::create([
        'server_id' => $server->id,
        'display_name' => 'Missing',
        'docker_network_name' => 'missing-net',
        'is_active' => true,
    ]);
    $manager = dockerNetworkManagerWithExecutor(fn () => null);

    expect($manager->inspect($network))->toBe([])
        ->and($network->refresh()->is_active)->toBeFalse();
});

it('deletes an empty managed network safely and removes the database record', function () {
    $server = $this->createFunctionalServer();
    $network = DockerNetwork::create([
        'server_id' => $server->id,
        'display_name' => 'Managed',
        'docker_network_name' => 'managed-net',
        'managed_by_coolify' => true,
        'external' => false,
        'is_system' => false,
        'is_active' => true,
        'last_inspect_data' => ['containers' => []],
    ]);
    $deleted = false;
    $manager = dockerNetworkManagerWithExecutor(function (Server $server, array $command) use (&$deleted) {
        if (str_starts_with($command[0], 'docker network rm')) {
            $deleted = true;

            return 'managed-net';
        }

        return $deleted ? null : $this->dockerInspectPayload('managed-net', ['Containers' => []]);
    });

    $manager->delete($network);

    expect(DockerNetwork::whereKey($network->id)->exists())->toBeFalse();
});

it('blocks unsafe delete scenarios', function (array $attributes) {
    $server = $this->createFunctionalServer();
    $network = DockerNetwork::create(array_merge([
        'server_id' => $server->id,
        'display_name' => 'Blocked',
        'docker_network_name' => 'blocked-net',
        'managed_by_coolify' => true,
        'external' => false,
        'is_system' => false,
        'is_active' => true,
    ], $attributes));

    expect(app(DockerNetworkManager::class)->canDelete($network)['allowed'])->toBeFalse();
})->with([
    'system' => [['is_system' => true]],
    'coolify infrastructure' => [['docker_network_name' => 'coolify']],
    'inactive' => [['is_active' => false]],
    'ingress' => [['last_inspect_data' => ['raw' => ['Ingress' => true], 'containers' => []]]],
]);

it('allows deleting an unused custom external network', function () {
    $server = $this->createFunctionalServer();
    $network = DockerNetwork::create([
        'server_id' => $server->id,
        'display_name' => 'External Custom',
        'docker_network_name' => 'external-custom',
        'managed_by_coolify' => false,
        'external' => true,
        'is_system' => false,
        'is_active' => true,
        'last_inspect_data' => ['containers' => []],
    ]);
    $commands = [];
    $deleted = false;
    $manager = dockerNetworkManagerWithExecutor(function (Server $server, array $command) use (&$commands, &$deleted) {
        $commands[] = $command[0];

        if (str_starts_with($command[0], 'docker network inspect')) {
            if ($deleted) {
                return null;
            }

            return $this->dockerInspectPayload('external-custom', ['Containers' => []]);
        }

        if (str_starts_with($command[0], 'docker network rm')) {
            $deleted = true;
        }

        return 'external-custom';
    });

    expect($manager->canDelete($network)['allowed'])->toBeTrue();

    $manager->delete($network);

    expect(DockerNetwork::whereKey($network->id)->exists())->toBeFalse()
        ->and($commands)->toContain("docker network rm -f 'external-custom'");
});

it('keeps inventory row when docker deletion fails', function () {
    $server = $this->createFunctionalServer();
    $network = DockerNetwork::create([
        'server_id' => $server->id,
        'display_name' => 'Managed',
        'docker_network_name' => 'managed-net',
        'managed_by_coolify' => true,
        'external' => false,
        'is_system' => false,
        'is_active' => true,
        'last_inspect_data' => ['containers' => []],
    ]);
    $manager = dockerNetworkManagerWithExecutor(function (Server $server, array $command) {
        if (str_starts_with($command[0], 'docker network rm')) {
            return null;
        }

        return $this->dockerInspectPayload('managed-net', ['Containers' => []]);
    });

    expect(fn () => $manager->delete($network))->toThrow(DockerNetworkDeletionException::class)
        ->and(DockerNetwork::whereKey($network->id)->exists())->toBeTrue();
});

it('blocks delete when network is configured as destination', function () {
    $server = $this->createFunctionalServer();
    $network = DockerNetwork::create([
        'server_id' => $server->id,
        'display_name' => 'Destination Network',
        'docker_network_name' => 'destination-net',
        'managed_by_coolify' => false,
        'external' => true,
        'is_active' => true,
        'last_inspect_data' => ['containers' => []],
    ]);

    StandaloneDocker::withoutEvents(function () use ($server) {
        (new StandaloneDocker)->forceFill([
            'uuid' => (string) new Cuid2,
            'server_id' => $server->id,
            'name' => 'Destination',
            'network' => 'destination-net',
        ])->save();
    });

    expect(app(DockerNetworkManager::class)->canDelete($network))
        ->toMatchArray([
            'allowed' => false,
            'reason_code' => 'destination_configured',
            'message' => 'Remove this network from Destinations before permanently deleting it.',
        ]);
});

it('preserves independent internal and proxy policy', function () {
    $server = $this->createFunctionalServer();
    $first = DockerNetwork::create([
        'server_id' => $server->id,
        'display_name' => 'First',
        'docker_network_name' => 'first-net',
        'is_active' => true,
        'proxy_access' => false,
    ]);
    $second = DockerNetwork::create([
        'server_id' => $server->id,
        'display_name' => 'Second',
        'docker_network_name' => 'second-net',
        'is_active' => true,
        'internal' => true,
    ]);

    $manager = dockerNetworkManagerWithExecutor(fn () => $this->dockerInspectPayload('second-net', [
        'Internal' => true,
        'Containers' => ['proxy-id' => ['Name' => 'coolify-proxy']],
    ]));
    $manager->updateProxyAccess($second, true);

    expect($first->refresh()->proxy_access)->toBeFalsy()
        ->and($second->refresh()->internal)->toBeTrue()
        ->and($second->proxy_access)->toBeTruthy();
});

it('forces internal network creation to keep proxy access disabled', function () {
    $server = $this->createFunctionalServer();
    $createdName = null;
    $commands = [];
    $manager = dockerNetworkManagerWithExecutor(function (Server $server, array $command) use (&$createdName, &$commands) {
        $commands[] = $command[0];

        if (str_starts_with($command[0], 'docker network create')) {
            expect($command[0])->toEndWith("'internal-net'");
            $createdName = 'internal-net';

            return $createdName;
        }

        return $this->dockerInspectPayload($createdName, ['Internal' => true]);
    });

    $network = $manager->create($server, [
        'display_name' => 'Internal',
        'docker_network_name' => 'internal-net',
        'driver' => 'bridge',
        'internal' => true,
        'proxy_access' => true,
    ]);

    expect($network->proxy_access)->toBeFalsy()
        ->and(collect($commands)->filter(fn ($command) => str_starts_with($command, 'docker network connect')))->toBeEmpty();
});

it('connects proxy during normal network creation only when explicitly enabled', function () {
    $server = $this->createFunctionalServer();
    $createdName = null;
    $attached = false;
    $commands = [];
    $manager = dockerNetworkManagerWithExecutor(function (Server $server, array $command) use (&$createdName, &$attached, &$commands) {
        $commands[] = $command[0];

        if (str_starts_with($command[0], 'docker network create')) {
            expect($command[0])->toEndWith("'public-net'");
            $createdName = 'public-net';

            return $createdName;
        }

        if (str_starts_with($command[0], 'docker network connect')) {
            $attached = true;

            return 'connected';
        }

        return $this->dockerInspectPayload($createdName, [
            'Containers' => $attached ? ['proxy-id' => ['Name' => 'coolify-proxy']] : [],
        ]);
    });

    $network = $manager->create($server, [
        'display_name' => 'Public',
        'docker_network_name' => 'public-net',
        'driver' => 'bridge',
        'internal' => false,
        'proxy_access' => true,
    ]);

    expect($network->proxy_access)->toBeTruthy()
        ->and(data_get($network->last_inspect_data, 'proxy_access_reconciled'))->toBeTrue()
        ->and(collect($commands)->contains(fn ($command) => str_starts_with($command, 'docker network connect')))->toBeTrue();
});

it('blocks delete when containers are connected', function () {
    $server = $this->createFunctionalServer();
    $network = DockerNetwork::create([
        'server_id' => $server->id,
        'display_name' => 'Managed',
        'docker_network_name' => 'managed-net',
        'managed_by_coolify' => true,
        'external' => false,
        'is_active' => true,
        'last_inspect_data' => ['containers' => ['container-id' => ['Name' => 'api']]],
    ]);
    $manager = dockerNetworkManagerWithExecutor(fn () => null);

    $eligibility = $manager->canDelete($network);

    expect($eligibility)
        ->toMatchArray([
            'allowed' => false,
            'reason_code' => 'has_connected_containers',
            'message' => 'This network cannot be permanently deleted because 1 container(s) are connected.',
            'container_count' => 1,
        ])
        ->and($eligibility['containers'][0]['name'])->toBe('api');
    expect(fn () => $manager->delete($network))
        ->toThrow(DockerNetworkDeletionException::class, $eligibility['message']);
});

it('allows deletion when only coolify proxy is connected and no active route depends on network', function () {
    $server = $this->createFunctionalServer();
    $network = DockerNetwork::create([
        'server_id' => $server->id,
        'display_name' => 'Proxy Only',
        'docker_network_name' => 'proxy-only-net',
        'managed_by_coolify' => true,
        'external' => false,
        'is_active' => true,
        'proxy_access' => true,
        'last_inspect_data' => [
            'containers' => ['proxy-id' => ['Name' => 'coolify-proxy']],
        ],
    ]);

    expect(app(DockerNetworkManager::class)->canDelete($network))
        ->toMatchArray([
            'allowed' => true,
            'container_count' => 1,
            'proxy_disconnect_required' => true,
        ]);
});

it('blocks application containers without attempting automatic disconnection', function () {
    $server = $this->createFunctionalServer();
    $network = DockerNetwork::create([
        'server_id' => $server->id,
        'display_name' => 'Application Network',
        'docker_network_name' => 'application-net',
        'managed_by_coolify' => true,
        'external' => false,
        'is_active' => true,
        'last_inspect_data' => [
            'containers' => [
                'app-id' => ['Name' => 'production-api'],
                'worker-id' => ['Name' => 'production-worker'],
            ],
        ],
    ]);
    $commands = [];
    $manager = dockerNetworkManagerWithExecutor(function (Server $server, array $command) use (&$commands) {
        $commands[] = $command[0];

        return null;
    });

    $eligibility = $manager->canDelete($network);

    expect($eligibility)
        ->toMatchArray([
            'allowed' => false,
            'reason_code' => 'has_connected_containers',
            'message' => 'This network cannot be permanently deleted because 2 container(s) are connected.',
            'container_count' => 2,
        ])
        ->and(array_column($eligibility['containers'], 'name'))
        ->toBe(['production-api', 'production-worker']);

    expect(fn () => $manager->delete($network))->toThrow(DockerNetworkDeletionException::class);
    expect($commands)->toBeEmpty();
});

it('blocks delete when managed active attachments exist', function () {
    $server = $this->createFunctionalServer();
    $network = DockerNetwork::create([
        'server_id' => $server->id,
        'display_name' => 'Managed',
        'docker_network_name' => 'managed-net',
        'managed_by_coolify' => true,
        'external' => false,
        'is_active' => true,
    ]);
    NetworkAttachment::create([
        'server_id' => $server->id,
        'docker_network_id' => $network->id,
        'is_managed' => true,
        'status' => NetworkAttachmentStatus::Attached,
    ]);

    expect(app(DockerNetworkManager::class)->canDelete($network)['allowed'])->toBeFalse();
});

it('blocks destination network deletion when legacy destination resources exist', function () {
    $server = $this->createFunctionalServer();
    $network = DockerNetwork::create([
        'server_id' => $server->id,
        'display_name' => 'Destination Network',
        'docker_network_name' => 'destination-net',
        'is_active' => true,
        'last_inspect_data' => ['containers' => []],
    ]);
    $destination = StandaloneDocker::withoutEvents(function () use ($server) {
        $destination = new StandaloneDocker;
        $destination->forceFill([
            'uuid' => (string) new Cuid2,
            'server_id' => $server->id,
            'name' => 'Destination',
            'network' => 'destination-net',
        ])->save();

        return $destination;
    });
    $project = Project::factory()->create(['team_id' => $server->team_id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    expect(app(DockerNetworkManager::class)->canDeleteWithDestination($network))
        ->toMatchArray([
            'allowed' => false,
            'reason_code' => 'has_attached_resources',
            'message' => 'This network cannot be permanently deleted because resources are attached.',
        ]);
});
