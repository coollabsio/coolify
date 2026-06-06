<?php

use App\Enums\DockerNetworkDriver;
use App\Enums\DockerNetworkRole;
use App\Enums\DockerNetworkSourceType;
use App\Enums\NetworkAttachmentStatus;
use App\Exceptions\DockerNetworkCreationException;
use App\Exceptions\DockerNetworkDeletionException;
use App\Exceptions\DockerNetworkImportException;
use App\Exceptions\DockerNetworkValidationException;
use App\Models\DockerNetwork;
use App\Models\NetworkAttachment;
use App\Models\Server;
use App\Models\Team;
use App\Services\Docker\DockerNetworkManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\InteractsWithDockerNetworks;

uses(RefreshDatabase::class, InteractsWithDockerNetworks::class);

function dockerNetworkManagerWithExecutor(Closure $executor): DockerNetworkManager
{
    return new DockerNetworkManager(executor: $executor);
}

it('creates a managed bridge network with generated technical name', function () {
    $server = $this->createFunctionalServer();
    $createdName = null;
    $manager = dockerNetworkManagerWithExecutor(function (Server $server, array $command) use (&$createdName) {
        if (str_starts_with($command[0], 'docker network inspect')) {
            return $createdName ? $this->dockerInspectPayload($createdName, ['Internal' => true, 'Attachable' => true]) : null;
        }

        if (str_starts_with($command[0], 'docker network create')) {
            preg_match("/'(coolify-net-[^']+)'$/", $command[0], $matches);
            $createdName = $matches[1];

            return $createdName;
        }

        return null;
    });

    $network = $manager->create($server, [
        'display_name' => 'Backend Private',
        'driver' => 'bridge',
        'subnet' => '172.30.10.0/24',
        'gateway' => '172.30.10.1',
        'internal' => true,
        'attachable' => true,
    ]);

    expect($network->display_name)->toBe('Backend Private')
        ->and($network->docker_network_name)->toStartWith('coolify-net-')
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

    expect(fn () => $manager->create($server, ['display_name' => 'Invalid', 'driver' => 'bridge', 'subnet' => 'abc']))
        ->toThrow(DockerNetworkValidationException::class);

    expect(fn () => $manager->create($server, ['display_name' => 'Invalid', 'driver' => 'bridge', 'subnet' => '172.30.11.0/24', 'gateway' => '172.30.10.1']))
        ->toThrow(DockerNetworkValidationException::class);

    expect(fn () => $manager->create($server, ['display_name' => 'Invalid', 'driver' => 'bridge', 'subnet' => '172.30.10.128/25']))
        ->toThrow(DockerNetworkValidationException::class);
});

it('does not create a database record if docker create fails', function () {
    $server = $this->createFunctionalServer();
    $manager = dockerNetworkManagerWithExecutor(fn () => null);

    expect(fn () => $manager->create($server, ['display_name' => 'No Runtime', 'driver' => 'bridge']))
        ->toThrow(DockerNetworkCreationException::class);

    expect(DockerNetwork::where('server_id', $server->id)->where('display_name', 'No Runtime')->exists())->toBeFalse();
});

it('imports an existing network and sets correct metadata', function () {
    $server = $this->createFunctionalServer();
    $manager = dockerNetworkManagerWithExecutor(fn () => $this->dockerInspectPayload('external-net'));

    $network = $manager->import($server, 'external-net', 'External Shared');

    expect($network->display_name)->toBe('External Shared')
        ->and($network->docker_network_name)->toBe('external-net')
        ->and($network->managed_by_coolify)->toBeTrue()
        ->and($network->external)->toBeFalse()
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
        ->and($network->managed_by_coolify)->toBeTrue()
        ->and($network->external)->toBeFalse();
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
        'IPAM' => ['Config' => [['Subnet' => '10.10.0.0/16', 'Gateway' => '10.10.0.1']]],
    ]));

    $manager->inspect($network);
    $network->refresh();

    expect($network->display_name)->toBe('Custom')
        ->and($network->managed_by_coolify)->toBeTrue()
        ->and($network->external)->toBeFalse()
        ->and($network->driver)->toBe(DockerNetworkDriver::Overlay)
        ->and($network->scope)->toBe(\App\Enums\DockerNetworkScope::Swarm)
        ->and($network->subnet)->toBe('10.10.0.0/16')
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

it('deletes an empty managed network safely and keeps the database record', function () {
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
    $manager = dockerNetworkManagerWithExecutor(fn () => $this->dockerInspectPayload('managed-net', ['Containers' => []]));

    $manager->delete($network);

    expect($network->refresh()->is_active)->toBeFalse()
        ->and(DockerNetwork::whereKey($network->id)->exists())->toBeTrue();
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
    'external' => [['external' => true]],
    'not managed' => [['managed_by_coolify' => false]],
    'inactive' => [['is_active' => false]],
]);

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

    expect($manager->canDelete($network)['allowed'])->toBeFalse();
    expect(fn () => $manager->delete($network))->toThrow(DockerNetworkDeletionException::class);
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
