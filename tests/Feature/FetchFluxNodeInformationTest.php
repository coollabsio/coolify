<?php

use App\Actions\Sentinel\FetchFluxNodeInformation;
use App\Models\Node;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('fetches and stores server information through Flux', function () {
    config()->set('constants.flux.internal_url', 'http://flux:7080');
    config()->set('constants.flux.internal_token', 'internal-secret');
    Http::fake([
        'http://flux:7080/v1/commands/system.info' => Http::response([
            'command_id' => 'command-1',
            'observed_at_unix_ms' => 1_789_140_000_000,
            'hostname' => 'worker-1',
            'operating_system' => 'Ubuntu',
            'operating_system_version' => '24.04',
            'kernel_version' => '6.8.0',
            'architecture' => 'x86_64',
            'cpu_count' => 8,
            'memory_bytes' => 17_179_869_184,
            'disk_total_bytes' => 536_870_912_000,
            'disk_available_bytes' => 322_122_547_200,
            'sentinel_version' => '1.0.1',
            'boot_id' => 'boot-1',
            'uptime_seconds' => 3600,
            'container_runtime' => 'podman',
            'container_runtime_version' => '5.4.2',
        ]),
    ]);
    $node = Node::factory()->create([
        'team_id' => Team::factory(),
        'metadata' => ['transfer' => ['status' => 'pending']],
    ]);

    $result = FetchFluxNodeInformation::run($node);
    $metadata = $node->fresh()->metadata;

    expect($result['hostname'])->toBe('worker-1')
        ->and($metadata)->toMatchArray([
            'hostname' => 'worker-1',
            'os' => 'Ubuntu 24.04',
            'arch' => 'x86_64',
            'kernel' => '6.8.0',
            'cpus' => 8,
            'memory_bytes' => 17_179_869_184,
            'disk_total_bytes' => 536_870_912_000,
            'disk_available_bytes' => 322_122_547_200,
            'sentinel_version' => '1.0.1',
            'boot_id' => 'boot-1',
            'container_runtime' => 'podman',
            'container_runtime_version' => '5.4.2',
            'source' => 'flux',
            'transfer' => ['status' => 'pending'],
        ]);

    Http::assertSent(fn ($request) => $request->url() === 'http://flux:7080/v1/commands/system.info'
        && $request->hasHeader('Authorization', 'Bearer internal-secret')
        && $request['server_id'] === $node->uuid);
});

it('rejects an invalid Flux server information response', function () {
    config()->set('constants.flux.internal_url', 'http://flux:7080');
    config()->set('constants.flux.internal_token', 'internal-secret');
    Http::fake(['*' => Http::response(['hostname' => ['invalid']])]);

    $node = Node::factory()->create([
        'team_id' => Team::factory(),
    ]);

    expect(fn () => FetchFluxNodeInformation::run($node))
        ->toThrow(RuntimeException::class, 'Flux returned an invalid server information response.');
});
