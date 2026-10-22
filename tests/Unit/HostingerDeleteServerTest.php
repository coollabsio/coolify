<?php

use App\Actions\Server\DeleteServer;
use App\Models\CloudProviderToken;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();

    $this->team = Team::factory()->create();
    $this->token = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => 'hostinger',
        'token' => 'test-hostinger-token',
    ]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
        'cloud_provider_token_id' => $this->token->id,
        'hostinger_virtual_machine_id' => 17923,
    ]);
    $this->server->delete();
});

it('deletes the Hostinger VPS by disabling auto-renewal when requested', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/virtual-machines/17923' => Http::response([
            'id' => 17923,
            'subscription_id' => 'Azz2Y9VWEjUO69lF',
        ]),
        'https://developers.hostinger.com/api/billing/v1/subscriptions/Azz2Y9VWEjUO69lF/auto-renewal/disable' => Http::response([
            'id' => 'Azz2Y9VWEjUO69lF',
            'is_auto_renewed' => false,
        ]),
    ]);

    DeleteServer::run(
        serverId: $this->server->id,
        cloudProviderTokenId: $this->token->id,
        teamId: $this->team->id,
        deleteFromHostinger: true,
        hostingerVirtualMachineId: 17923,
    );

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://developers.hostinger.com/api/billing/v1/subscriptions/Azz2Y9VWEjUO69lF/auto-renewal/disable');
    expect(Server::withTrashed()->find($this->server->id))->toBeNull();
});

it('retains the server when the Hostinger VPS cannot be deleted', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/virtual-machines/17923' => Http::response([
            'id' => 17923,
            'subscription_id' => 'Azz2Y9VWEjUO69lF',
        ]),
        'https://developers.hostinger.com/api/billing/v1/subscriptions/Azz2Y9VWEjUO69lF/auto-renewal/disable' => Http::response([
            'message' => 'Unauthorized',
        ], 403),
    ]);

    expect(fn () => DeleteServer::run(
        serverId: $this->server->id,
        cloudProviderTokenId: $this->token->id,
        teamId: $this->team->id,
        deleteFromHostinger: true,
        hostingerVirtualMachineId: 17923,
    ))->toThrow(Exception::class, 'Hostinger API error: Unauthorized');

    expect(Server::withTrashed()->find($this->server->id))->not->toBeNull();
});

it('does not call Hostinger when the VPS should be kept', function () {
    DeleteServer::run(
        serverId: $this->server->id,
        cloudProviderTokenId: $this->token->id,
        teamId: $this->team->id,
        hostingerVirtualMachineId: 17923,
    );

    Http::assertNothingSent();
    expect(Server::withTrashed()->find($this->server->id))->toBeNull();
});
