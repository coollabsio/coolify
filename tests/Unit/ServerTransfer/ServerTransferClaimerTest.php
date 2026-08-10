<?php

use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Services\ServerTransfer\ServerTransferClaimer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true, 'fqdn' => 'https://coolify-a.test']);

    $this->team = Team::factory()->create();
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'ip' => '10.77.0.10',
        'name' => 'claim-me',
        'server_metadata' => [
            'transfer' => [
                'status' => 'imported',
                'export_id' => 'export-xyz',
            ],
        ],
    ]);
});

test('markTransferred writes force_disabled and transfer status together', function () {
    $result = app(ServerTransferClaimer::class)->markTransferred(
        $this->server,
        exportId: 'export-xyz',
        targetInstanceUrl: 'https://coolify-b.test',
    );

    expect($result['server_uuid'])->toBe($this->server->uuid);

    $this->server->refresh();
    expect(data_get($this->server->server_metadata, 'transfer.status'))->toBe('transferred')
        ->and(data_get($this->server->server_metadata, 'transfer.export_id'))->toBe('export-xyz')
        ->and(data_get($this->server->server_metadata, 'transfer.target_instance_url'))->toBe('https://coolify-b.test')
        ->and((bool) $this->server->settings->force_disabled)->toBeTrue()
        ->and((bool) $this->server->settings->is_sentinel_enabled)->toBeFalse();
});

test('claim persists ownership metadata transactionally without remote write', function () {
    $result = app(ServerTransferClaimer::class)->claim(
        $this->server,
        writeRemote: false,
        rebindSentinel: true,
    );

    expect($result['server_uuid'])->toBe($this->server->uuid)
        ->and($result['claim_written'])->toBeFalse()
        ->and($result['sentinel_rebound'])->toBeTrue()
        ->and(data_get($result, 'claim.instance_url'))->toBe('https://coolify-a.test');

    $this->server->refresh();
    expect(data_get($this->server->server_metadata, 'transfer.status'))->toBe('claimed')
        ->and(data_get($this->server->server_metadata, 'transfer.claim_written'))->toBeFalse()
        ->and(data_get($this->server->server_metadata, 'transfer.export_id'))->toBe('export-xyz')
        ->and((string) $this->server->settings->sentinel_custom_url)->toBe('https://coolify-a.test');
});
