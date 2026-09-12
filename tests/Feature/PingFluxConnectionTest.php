<?php

use App\Actions\Sentinel\PingFluxConnection;
use App\Models\Node;
use App\Models\Server;
use Illuminate\Support\Facades\Http;

it('sends an authenticated ping to the internal Flux API', function () {
    config()->set('constants.flux.internal_url', 'http://flux:7080');
    config()->set('constants.flux.internal_token', 'internal-secret');
    Http::fake([
        'http://flux:7080/v1/commands/system.ping' => Http::response([
            'command_id' => 'command-1',
            'nonce' => 'nonce-1',
            'sentinel_time_unix_ms' => 1_789_140_000_000,
            'sentinel_version' => 'main',
            'boot_id' => 'boot-1',
        ]),
    ]);
    $node = new Node;
    $node->uuid = 'server-1';

    $result = PingFluxConnection::run($node);

    expect($result)->toMatchArray([
        'command_id' => 'command-1',
        'sentinel_version' => 'main',
        'boot_id' => 'boot-1',
    ])->toHaveKey('latency_ms');
    Http::assertSent(fn ($request) => $request->url() === 'http://flux:7080/v1/commands/system.ping'
        && $request->hasHeader('Authorization', 'Bearer internal-secret')
        && $request['server_id'] === 'server-1');
});

it('does not send Flux commands to legacy servers', function () {
    Http::fake();

    expect(fn () => PingFluxConnection::run(new Server))
        ->toThrow(TypeError::class);

    Http::assertNothingSent();
});
