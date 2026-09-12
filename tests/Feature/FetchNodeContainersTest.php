<?php

use App\Actions\Node\FetchContainers;
use App\Enums\NodeContainerManagementState;
use App\Models\Node;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('fetches, validates, and reconciles a complete container snapshot through Flux', function () {
    config()->set('constants.flux.internal_url', 'http://flux:7080');
    config()->set('constants.flux.internal_token', 'internal-secret');
    Http::fake([
        'http://flux:7080/v1/commands/container.list' => Http::response([
            'command_id' => 'command-1',
            'observed_at_unix_ms' => 1_789_237_260_000,
            'containers' => [[
                'runtime_id' => 'container-1',
                'name' => 'external-nginx',
                'image' => 'docker.io/library/nginx:latest',
                'state' => 'running',
                'health_status' => null,
                'restart_count' => 0,
                'ports' => [[
                    'host_ip' => '0.0.0.0',
                    'host_port' => 8080,
                    'container_port' => 80,
                    'protocol' => 'tcp',
                ]],
                'labels' => ['vendor' => 'example'],
                'created_at_unix_ms' => 1_789_237_060_000,
                'started_at_unix_ms' => 1_789_237_061_000,
            ]],
        ]),
    ]);
    $node = Node::factory()->create(['team_id' => Team::factory()]);

    $count = FetchContainers::run($node);

    expect($count)->toBe(1);
    $container = $node->containers()->firstOrFail();
    expect($container->name)->toBe('external-nginx')
        ->and($container->management_state)->toBe(NodeContainerManagementState::EXTERNAL)
        ->and($container->observed_at->timestamp)->toBe(1_789_237_260)
        ->and($container->runtime_created_at->timestamp)->toBe(1_789_237_060)
        ->and($container->ports[0]['host_port'])->toBe(8080);
    Http::assertSent(fn ($request) => $request->url() === 'http://flux:7080/v1/commands/container.list'
        && $request->hasHeader('Authorization', 'Bearer internal-secret')
        && $request['server_id'] === $node->uuid);
});

it('does not replace stored inventory when Flux returns an invalid response', function () {
    config()->set('constants.flux.internal_url', 'http://flux:7080');
    config()->set('constants.flux.internal_token', 'internal-secret');
    Http::fake(['*' => Http::response(['containers' => [['runtime_id' => null]]])]);
    $node = Node::factory()->create(['team_id' => Team::factory()]);
    $node->containers()->create([
        'runtime_id' => 'existing',
        'name' => 'existing',
        'image' => 'alpine',
        'state' => 'running',
        'labels' => [],
        'management_state' => NodeContainerManagementState::EXTERNAL,
        'observed_at' => now(),
    ]);

    expect(fn () => FetchContainers::run($node))
        ->toThrow(RuntimeException::class, 'Flux returned invalid container inventory.')
        ->and($node->containers()->pluck('runtime_id')->all())->toBe(['existing']);
});
