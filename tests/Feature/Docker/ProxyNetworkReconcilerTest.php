<?php

use App\Enums\NetworkAttachmentStatus;
use App\Exceptions\ProxyNetworkReconciliationException;
use App\Models\DockerNetwork;
use App\Models\NetworkAttachment;
use App\Models\Server;
use App\Services\Docker\ProxyNetworkReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\InteractsWithDockerNetworks;

uses(RefreshDatabase::class, InteractsWithDockerNetworks::class);

function proxyRuntimePayload(string $network, bool $attached): string
{
    return json_encode([[
        'Name' => $network,
        'Id' => "{$network}-id",
        'Driver' => 'bridge',
        'Scope' => 'local',
        'Internal' => false,
        'Attachable' => true,
        'IPAM' => ['Config' => []],
        'Containers' => $attached ? [
            'proxy-id' => ['Name' => 'coolify-proxy'],
        ] : [],
    ]]);
}

function proxyNetwork(Server $server, array $attributes = []): DockerNetwork
{
    return DockerNetwork::create(array_merge([
        'server_id' => $server->id,
        'display_name' => 'Proxy Network',
        'docker_network_name' => 'proxy-net',
        'is_active' => true,
        'proxy_access' => false,
    ], $attributes));
}

it('enables proxy access by connecting and verifying runtime before persisting', function () {
    $server = $this->createFunctionalServer();
    $network = proxyNetwork($server);
    $attached = false;
    $commands = [];
    $reconciler = new ProxyNetworkReconciler(executor: function (Server $server, array $command) use (&$attached, &$commands) {
        $commands[] = $command[0];

        if (str_starts_with($command[0], 'docker network inspect')) {
            return proxyRuntimePayload('proxy-net', $attached);
        }

        $attached = true;

        return 'connected';
    });

    $reconciler->enable($network);

    expect($network->refresh()->proxy_access)->toBeTruthy()
        ->and(data_get($network->last_inspect_data, 'proxy_access_runtime'))->toBeTrue()
        ->and(data_get($network->last_inspect_data, 'proxy_access_reconciled'))->toBeTrue()
        ->and($commands)->toContain("docker network connect 'proxy-net' 'coolify-proxy'");
});

it('enables idempotently when proxy is already attached', function () {
    $server = $this->createFunctionalServer();
    $network = proxyNetwork($server);
    $commands = [];
    $reconciler = new ProxyNetworkReconciler(executor: function (Server $server, array $command) use (&$commands) {
        $commands[] = $command[0];

        return proxyRuntimePayload('proxy-net', true);
    });

    $reconciler->enable($network);

    expect($network->refresh()->proxy_access)->toBeTruthy()
        ->and(collect($commands)->filter(fn ($command) => str_starts_with($command, 'docker network connect')))->toBeEmpty();
});

it('disables proxy access by disconnecting and verifying runtime before persisting', function () {
    $server = $this->createFunctionalServer();
    $network = proxyNetwork($server, ['proxy_access' => true]);
    $attached = true;
    $reconciler = new ProxyNetworkReconciler(executor: function (Server $server, array $command) use (&$attached) {
        if (str_starts_with($command[0], 'docker network inspect')) {
            return proxyRuntimePayload('proxy-net', $attached);
        }

        $attached = false;

        return 'disconnected';
    });

    $reconciler->disable($network);

    expect($network->refresh()->proxy_access)->toBeFalsy()
        ->and(data_get($network->last_inspect_data, 'proxy_access_runtime'))->toBeFalse()
        ->and(data_get($network->last_inspect_data, 'proxy_access_reconciled'))->toBeTrue();
});

it('preserves persisted state when docker operation fails', function () {
    $server = $this->createFunctionalServer();
    $network = proxyNetwork($server);
    $reconciler = new ProxyNetworkReconciler(executor: fn (Server $server, array $command) => str_starts_with($command[0], 'docker network inspect')
        ? proxyRuntimePayload('proxy-net', false)
        : null);

    expect(fn () => $reconciler->enable($network))
        ->toThrow(ProxyNetworkReconciliationException::class)
        ->and($network->refresh()->proxy_access)->toBeFalsy();
});

it('detects desired and runtime drift without falsifying persisted policy', function () {
    $server = $this->createFunctionalServer();
    $network = proxyNetwork($server, ['proxy_access' => true]);
    $reconciler = new ProxyNetworkReconciler(executor: fn () => proxyRuntimePayload('proxy-net', false));

    $reconciler->detectDrift($network);

    expect($network->refresh()->proxy_access)->toBeTruthy()
        ->and(data_get($network->last_inspect_data, 'proxy_access_runtime'))->toBeFalse()
        ->and(data_get($network->last_inspect_data, 'proxy_access_reconciled'))->toBeFalse();
});

it('adopts attached runtime state when no persisted preference exists without mutating docker', function () {
    $server = $this->createFunctionalServer();
    $network = proxyNetwork($server, ['proxy_access' => null]);
    $commands = [];
    $reconciler = new ProxyNetworkReconciler(executor: function (Server $server, array $command) use (&$commands) {
        $commands[] = $command[0];

        return proxyRuntimePayload('proxy-net', true);
    });

    $reconciler->detectDrift($network);

    expect($network->refresh()->proxy_access)->toBeTruthy()
        ->and(data_get($network->last_inspect_data, 'proxy_access_reconciled'))->toBeTrue()
        ->and(collect($commands)->every(fn ($command) => str_starts_with($command, 'docker network inspect')))->toBeTrue();
});

it('adopts detached runtime state once and preserves preference on later drift', function () {
    $server = $this->createFunctionalServer();
    $network = proxyNetwork($server, ['proxy_access' => null]);
    $attached = false;
    $reconciler = new ProxyNetworkReconciler(executor: function () use (&$attached) {
        return proxyRuntimePayload('proxy-net', $attached);
    });

    $reconciler->detectDrift($network);
    $attached = true;
    $reconciler->detectDrift($network->refresh());

    expect($network->refresh()->proxy_access)->toBeFalsy()
        ->and(data_get($network->last_inspect_data, 'proxy_access_runtime'))->toBeTrue()
        ->and(data_get($network->last_inspect_data, 'proxy_access_reconciled'))->toBeFalse();
});

it('blocks disabling while a required configured attachment depends on network', function () {
    $server = $this->createFunctionalServer();
    $network = proxyNetwork($server, ['proxy_access' => true]);
    NetworkAttachment::create([
        'server_id' => $server->id,
        'docker_network_id' => $network->id,
        'is_managed' => true,
        'is_required' => true,
        'status' => NetworkAttachmentStatus::Attached,
    ]);
    $reconciler = new ProxyNetworkReconciler(executor: fn () => proxyRuntimePayload('proxy-net', true));

    expect(fn () => $reconciler->disable($network))
        ->toThrow(ProxyNetworkReconciliationException::class, 'configured required network attachment')
        ->and($network->refresh()->proxy_access)->toBeTruthy();
});
