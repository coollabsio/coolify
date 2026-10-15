<?php

use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Services\ServerTransfer\ServerTransferClaimer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // A claim writes the owner marker on the host over SSH.
    Process::fake();
    Storage::fake('ssh-keys');
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

test('claim persists ownership metadata and always rebinds Sentinel', function () {
    $result = app(ServerTransferClaimer::class)->claim($this->server);

    expect($result['server_uuid'])->toBe($this->server->uuid)
        ->and($result)->not->toHaveKey('claim_written')
        ->and(data_get($result, 'claim.instance_url'))->toBe('https://coolify-a.test');

    $this->server->refresh();
    expect(data_get($this->server->server_metadata, 'transfer.status'))->toBe('claimed')
        ->and(data_get($this->server->server_metadata, 'transfer.export_id'))->toBe('export-xyz')
        ->and((bool) $this->server->settings->is_sentinel_enabled)->toBeTrue()
        ->and((string) $this->server->settings->sentinel_custom_url)->toBe('https://coolify-a.test');
});

test('claim writes the owner marker with its claim id on the host', function () {
    $result = app(ServerTransferClaimer::class)->claim($this->server);

    $claimId = data_get($result, 'claim.claim_id');
    expect($claimId)->toBeString()->not->toBeEmpty()
        ->and(data_get($this->server->fresh()->server_metadata, 'transfer.claim.claim_id'))->toBe($claimId);

    Process::assertRan(function ($process) use ($claimId) {
        $marker = json_decode((string) $process->input, true);

        return str_contains($process->command, ServerTransferClaimer::OWNER_MARKER_PATH)
            && data_get($marker, 'claim_id') === $claimId
            && data_get($marker, 'instance_url') === 'https://coolify-a.test';
    });
});

test('claim changes nothing when the owner marker cannot be written', function () {
    Process::fake(['*' => Process::result(errorOutput: 'Permission denied', exitCode: 1)]);
    $sentinelUrlBefore = $this->server->settings->sentinel_custom_url;

    expect(fn () => app(ServerTransferClaimer::class)->claim($this->server))->toThrow(RuntimeException::class);

    $this->server->refresh();
    expect(data_get($this->server->server_metadata, 'transfer.status'))->toBe('imported')
        ->and(data_get($this->server->server_metadata, 'transfer.claim'))->toBeNull()
        ->and($this->server->settings->sentinel_custom_url)->toBe($sentinelUrlBefore);
});

test('a server is released when its owner marker names the claim of another instance', function () {
    app(ServerTransferClaimer::class)->claim($this->server);

    $released = app(ServerTransferClaimer::class)->releaseIfClaimedElsewhere($this->server->fresh(), json_encode([
        'claim_id' => 'claim-of-instance-b',
        'instance_url' => 'https://coolify-b.test',
    ]));

    $this->server->refresh();
    expect($released)->toBeTrue()
        ->and($this->server->isTransferredAway())->toBeTrue()
        ->and(data_get($this->server->server_metadata, 'transfer.target_instance_url'))->toBe('https://coolify-b.test')
        ->and((bool) $this->server->settings->force_disabled)->toBeTrue()
        ->and((bool) $this->server->settings->is_sentinel_enabled)->toBeFalse();
});

test('a server is not released when the owner marker is its own claim or missing', function (?string $marker) {
    $claimId = data_get(app(ServerTransferClaimer::class)->claim($this->server), 'claim.claim_id');
    $marker = $marker === 'own' ? json_encode(['claim_id' => $claimId, 'instance_url' => 'https://coolify-a.test']) : $marker;

    $released = app(ServerTransferClaimer::class)->releaseIfClaimedElsewhere($this->server->fresh(), $marker);

    expect($released)->toBeFalse()
        ->and($this->server->fresh()->isTransferredAway())->toBeFalse()
        ->and((bool) $this->server->fresh()->settings->force_disabled)->toBeFalse();
})->with([
    'own claim' => 'own',
    'no marker' => '',
    'unreadable marker' => 'not json',
    'marker without claim id' => '{"instance_url":"https://coolify-b.test"}',
]);

test('a server that this instance never claimed ignores the owner marker', function () {
    $released = app(ServerTransferClaimer::class)->releaseIfClaimedElsewhere($this->server, json_encode([
        'claim_id' => 'left-over-claim-of-a-deleted-instance',
        'instance_url' => 'https://coolify-b.test',
    ]));

    expect($released)->toBeFalse()
        ->and($this->server->fresh()->isTransferredAway())->toBeFalse();
});
