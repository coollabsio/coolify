<?php

use App\Actions\Server\ValidateServer;
use App\Jobs\ValidateAndInstallServerJob;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->team = Team::factory()->create();
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
});

test('transferred servers cannot be validated', function () {
    expect($this->server->canBeValidated())->toBeTrue()
        ->and($this->server->isTransferredAway())->toBeFalse();

    $this->server->server_metadata = [
        'transfer' => ['status' => 'transferred'],
    ];
    $this->server->save();
    $this->server->forceDisableServer();

    expect($this->server->fresh()->isTransferredAway())->toBeTrue()
        ->and($this->server->fresh()->canBeValidated())->toBeFalse();
});

test('forceEnableServer does not clear transferred servers', function () {
    $this->server->server_metadata = [
        'transfer' => ['status' => 'transferred'],
    ];
    $this->server->save();
    $this->server->forceDisableServer();

    $this->server->forceEnableServer();

    expect((bool) $this->server->fresh()->settings->force_disabled)->toBeTrue()
        ->and($this->server->fresh()->canBeValidated())->toBeFalse();
});

test('ValidateServer action rejects transferred servers', function () {
    $this->server->server_metadata = [
        'transfer' => ['status' => 'transferred'],
    ];
    $this->server->save();

    expect(fn () => ValidateServer::run($this->server))
        ->toThrow(Exception::class, 'transferred');
});

test('ValidateAndInstallServerJob no-ops for transferred servers', function () {
    $this->server->server_metadata = [
        'transfer' => ['status' => 'transferred'],
    ];
    $this->server->save();

    (new ValidateAndInstallServerJob($this->server))->handle();

    expect((bool) $this->server->fresh()->is_validating)->toBeFalse()
        ->and((string) $this->server->fresh()->validation_logs)->toContain('transferred');
});
