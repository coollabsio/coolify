<?php

use App\Models\CloudProviderToken;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('refreshes Hostinger virtual machine status and public IP', function () {
    $team = Team::factory()->create();
    $token = CloudProviderToken::factory()->create([
        'team_id' => $team->id,
        'provider' => 'hostinger',
        'token' => 'hostinger-token',
    ]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'cloud_provider_token_id' => $token->id,
        'hostinger_virtual_machine_id' => 17923,
        'hostinger_virtual_machine_status' => 'creating',
        'ip' => '0.0.0.0',
    ]);

    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/virtual-machines/17923' => Http::response([
            'id' => 17923,
            'state' => 'running',
            'ipv4' => [['address' => '203.0.113.10']],
        ]),
    ]);

    expect($server->refreshHostingerState())->toBe('running')
        ->and($server->fresh()->hostinger_virtual_machine_status)->toBe('running')
        ->and($server->fresh()->ip)->toBe('203.0.113.10');
});
