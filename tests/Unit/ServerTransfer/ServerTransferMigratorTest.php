<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\ServerTransfer\ServerTransferClaimer;
use App\Services\ServerTransfer\ServerTransferMigrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true, 'fqdn' => 'https://coolify-a.test']);

    $this->team = Team::factory()->create();
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'ip' => '10.88.0.10',
        'name' => 'migrate-me',
    ]);
    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);
    Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'name' => 'app-on-server',
        'ports_exposes' => '3000',
    ]);
});

test('migrate exports imports via http and completes locally', function () {
    Http::fake([
        'http://8.8.8.8/api/v1/servers/import' => Http::response([
            'dry_run' => false,
            'server_uuid' => $this->server->uuid,
            'claimed' => true,
            'export_id' => 'remote-export',
            'created' => ['applications' => 1],
            'warnings' => [],
        ], 201),
    ]);

    $result = app(ServerTransferMigrator::class)->migrate(
        server: $this->server,
        targetUrl: 'http://8.8.8.8',
        targetToken: 'target-token-xyz',
        writeRemote: false,
    );

    expect($result['server_uuid'])->toBe($this->server->uuid)
        ->and($result['target_url'])->toBe('http://8.8.8.8')
        ->and($result['import']['claimed'])->toBeTrue()
        ->and($result['message'])->toContain('migrated');

    $this->server->refresh();
    expect(data_get($this->server->server_metadata, 'transfer.status'))->toBe('transferred')
        ->and((bool) $this->server->settings->force_disabled)->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->url() === 'http://8.8.8.8/api/v1/servers/import'
            && $request->hasHeader('Authorization', 'Bearer target-token-xyz')
            && data_get($request->data(), 'claim') === true
            && data_get($request->data(), 'bundle.server.uuid') === $this->server->uuid;
    });
});

test('migrate rejects a private target before sending the bundle', function () {
    Http::fake();

    expect(fn () => app(ServerTransferMigrator::class)->migrate(
        $this->server,
        'http://127.0.0.1:8001',
        'token',
    ))->toThrow(ValidationException::class);

    Http::assertNothingSent();
});

test('migrate fails clearly when target is unreachable', function () {
    Http::fake([
        'http://8.8.4.4/*' => Http::failedConnection(),
    ]);

    expect(fn () => app(ServerTransferMigrator::class)->migrate(
        $this->server,
        'http://8.8.4.4',
        'token',
    ))->toThrow(RuntimeException::class, 'Could not reach target');
});

test('migrate fails when target returns error', function () {
    Http::fake([
        'http://8.8.8.8/api/v1/servers/import' => Http::response([
            'message' => 'A server with IP/domain already exists',
        ], 422),
    ]);

    expect(fn () => app(ServerTransferMigrator::class)->migrate(
        $this->server,
        'http://8.8.8.8',
        'token',
    ))->toThrow(RuntimeException::class, 'Target import failed');

    // Source must remain unmanaged-away only after successful remote import+complete.
    $this->server->refresh();
    expect(data_get($this->server->server_metadata, 'transfer.status'))->not->toBe('transferred')
        ->and((bool) $this->server->settings->force_disabled)->toBeFalse();
});

test('migrate surfaces recovery guidance when complete fails after successful remote import', function () {
    Http::fake([
        'http://8.8.8.8/api/v1/servers/import' => Http::response([
            'dry_run' => false,
            'server_uuid' => $this->server->uuid,
            'claimed' => true,
            'warnings' => [],
        ], 201),
    ]);

    $claimer = Mockery::mock(ServerTransferClaimer::class)->makePartial();
    $claimer->shouldReceive('markTransferred')
        ->once()
        ->andThrow(new RuntimeException('simulated complete failure'));
    app()->instance(ServerTransferClaimer::class, $claimer);

    expect(fn () => app(ServerTransferMigrator::class)->migrate(
        $this->server,
        'http://8.8.8.8',
        'token',
    ))->toThrow(RuntimeException::class, 'Retry complete');
});
