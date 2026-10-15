<?php

use App\Jobs\MigrateServerToInstanceJob;
use App\Jobs\ValidateAndInstallServerJob;
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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // A claim writes the owner marker on the host over SSH.
    Process::fake();
    Storage::fake('ssh-keys');
    // An import validates the claimed server over SSH; the sync test queue would run it.
    Queue::fake([ValidateAndInstallServerJob::class]);
    Sleep::fake();
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
        'https://8.8.8.8/api/v1/servers/import' => Http::response([
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
        targetUrl: 'https://8.8.8.8',
        targetToken: 'target-token-xyz',
    );

    expect($result['server_uuid'])->toBe($this->server->uuid)
        ->and($result['target_url'])->toBe('https://8.8.8.8')
        ->and($result['import']['claimed'])->toBeTrue()
        ->and($result['message'])->toContain('migrated');

    $this->server->refresh();
    expect(data_get($this->server->server_metadata, 'transfer.status'))->toBe('transferred')
        ->and((bool) $this->server->settings->force_disabled)->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://8.8.8.8/api/v1/servers/import'
            && $request->hasHeader('Authorization', 'Bearer target-token-xyz')
            && ! array_key_exists('write_remote', $request->data())
            && data_get($request->data(), 'bundle.server.uuid') === $this->server->uuid;
    });
});

test('migrate rejects a private target before sending the bundle', function () {
    config()->set('app.env', 'production');
    Http::fake();

    expect(fn () => app(ServerTransferMigrator::class)->migrate(
        $this->server,
        'https://127.0.0.1:8001',
        'token',
    ))->toThrow(ValidationException::class);

    Http::assertNothingSent();
});

test('migrate reaches a local peer instance in development', function () {
    config()->set('app.env', 'local');
    Http::fake(fn () => Http::response([
        'dry_run' => false,
        'server_uuid' => $this->server->uuid,
        'claimed' => true,
        'warnings' => [],
    ], 201));

    app(ServerTransferMigrator::class)->migrate(
        $this->server,
        'http://localhost:8001',
        'target-token',
    );

    $targetHost = file_exists('/.dockerenv') || is_file('/run/.containerenv')
        ? 'host.docker.internal'
        : 'localhost';
    Http::assertSent(fn ($request) => $request->url() === "http://{$targetHost}:8001/api/v1/servers/import");
});

test('migrate still rejects other private targets in development', function () {
    config()->set('app.env', 'local');
    Http::fake();

    expect(fn () => app(ServerTransferMigrator::class)->migrate(
        $this->server,
        'https://10.0.0.5:8001',
        'token',
    ))->toThrow(ValidationException::class);

    Http::assertNothingSent();
});

test('migrate fails clearly when target is unreachable', function () {
    Http::fake([
        'https://8.8.4.4/*' => Http::failedConnection(),
    ]);

    expect(fn () => app(ServerTransferMigrator::class)->migrate(
        $this->server,
        'https://8.8.4.4',
        'token',
    ))->toThrow(RuntimeException::class, 'Could not reach target');
});

test('migrate fails when target returns error', function () {
    Http::fake([
        'https://8.8.8.8/api/v1/servers/import' => Http::response([
            'message' => 'A server with IP/domain already exists',
        ], 422),
    ]);

    expect(fn () => app(ServerTransferMigrator::class)->migrate(
        $this->server,
        'https://8.8.8.8',
        'token',
    ))->toThrow(RuntimeException::class, 'Target import failed');

    // Source must remain unmanaged-away only after successful remote import+complete.
    $this->server->refresh();
    expect(data_get($this->server->server_metadata, 'transfer.status'))->not->toBe('transferred')
        ->and((bool) $this->server->settings->force_disabled)->toBeFalse();
});

test('migrate surfaces recovery guidance when complete fails after successful remote import', function () {
    Http::fake([
        'https://8.8.8.8/api/v1/servers/import' => Http::response([
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
        'https://8.8.8.8',
        'token',
    ))->toThrow(RuntimeException::class, 'transfer/complete on this instance');
});

test('migrate keeps managing the server here when the target could not take management', function () {
    Http::fake([
        'https://8.8.8.8/api/v1/servers/import' => Http::response([
            'dry_run' => false,
            'server_uuid' => $this->server->uuid,
            'claimed' => false,
            'warnings' => ['Server imported, but automatic claim failed: boom'],
        ], 201),
    ]);

    expect(fn () => app(ServerTransferMigrator::class)->migrate(
        $this->server,
        'https://8.8.8.8',
        'token',
    ))->toThrow(RuntimeException::class, 'could not take management');

    $this->server->refresh();
    expect($this->server->isTransferredAway())->toBeFalse()
        ->and((bool) $this->server->settings->force_disabled)->toBeFalse();
});

test('migrate rejects a target without https outside development', function () {
    Http::fake();

    expect(fn () => app(ServerTransferMigrator::class)->migrate(
        $this->server,
        'http://8.8.8.8',
        'token',
    ))->toThrow(RuntimeException::class, 'must use https');

    Http::assertNothingSent();
});

test('migrate tells the user to upgrade a target without server transfer', function () {
    Http::fake([
        'https://8.8.8.8/api/v1/servers/import' => Http::response(['message' => 'Not found.'], 404),
    ]);

    expect(fn () => app(ServerTransferMigrator::class)->migrate(
        $this->server,
        'https://8.8.8.8',
        'token',
    ))->toThrow(RuntimeException::class, 'does not support server transfer');
});

test('migrate completes when the import request times out but the target claimed the server', function () {
    $sentExportId = null;
    Http::fake(function ($request) use (&$sentExportId) {
        if (str_ends_with($request->url(), '/api/v1/servers/import')) {
            $sentExportId = data_get($request->data(), 'bundle.export_id');

            return Http::response('Gateway Timeout', 504);
        }

        return Http::response([
            'server_uuid' => $this->server->uuid,
            'transfer' => ['status' => 'claimed', 'export_id' => $sentExportId],
        ]);
    });

    $result = app(ServerTransferMigrator::class)->migrate($this->server, 'https://8.8.8.8', 'token');

    expect($result['import']['claimed'])->toBeTrue()
        ->and($this->server->fresh()->isTransferredAway())->toBeTrue();
    Http::assertSent(fn ($request) => $request->url() === "https://8.8.8.8/api/v1/servers/{$this->server->uuid}/transfer");
});

test('migrate keeps the server here when the target never confirms a timed out import', function () {
    Http::fake(function ($request) {
        if (str_ends_with($request->url(), '/api/v1/servers/import')) {
            return Http::response('Gateway Timeout', 504);
        }

        return Http::response(['message' => 'Server not found.'], 404);
    });

    expect(fn () => app(ServerTransferMigrator::class)->migrate($this->server, 'https://8.8.8.8', 'token'))
        ->toThrow(RuntimeException::class, 'Target import failed (HTTP 504)');

    expect($this->server->fresh()->isTransferredAway())->toBeFalse();
});

test('start records the running transfer and queues the job once', function () {
    Queue::fake();
    Http::fake(['*/api/v1/servers/import/check*' => Http::response(['message' => 'ok', 'checks' => []])]);
    $migrator = app(ServerTransferMigrator::class);

    $migrator->start($this->server, 'https://8.8.8.8/', 'token');

    expect(data_get($this->server->fresh()->server_metadata, 'migration.status'))->toBe('running')
        ->and(data_get($this->server->fresh()->server_metadata, 'migration.target_url'))->toBe('https://8.8.8.8');
    Queue::assertPushed(MigrateServerToInstanceJob::class, 1);

    expect(fn () => $migrator->start($this->server, 'https://8.8.8.8', 'token'))
        ->toThrow(RuntimeException::class, 'already running');
    Queue::assertPushed(MigrateServerToInstanceJob::class, 1);
});

test('the migrate job records a failed transfer', function () {
    Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    (new MigrateServerToInstanceJob($this->server, 'https://8.8.8.8', 'token'))
        ->handle(app(ServerTransferMigrator::class));

    $migration = data_get($this->server->fresh()->server_metadata, 'migration');
    expect($migration['status'])->toBe('failed')
        ->and($migration['error'])->toContain('HTTP 401');
});

test('the migrate job records a finished transfer', function () {
    Http::fake([
        'https://8.8.8.8/api/v1/servers/import' => Http::response([
            'server_uuid' => $this->server->uuid,
            'claimed' => true,
            'warnings' => ['check webhooks'],
        ], 201),
    ]);

    (new MigrateServerToInstanceJob($this->server, 'https://8.8.8.8', 'token'))
        ->handle(app(ServerTransferMigrator::class));

    $server = $this->server->fresh();
    expect(data_get($server->server_metadata, 'migration.status'))->toBe('succeeded')
        ->and(data_get($server->server_metadata, 'migration.warnings'))->toContain('check webhooks')
        ->and($server->isTransferredAway())->toBeTrue();
});

test('start replaces a running state that a stopped worker left behind', function () {
    Queue::fake();
    Http::fake(['*/api/v1/servers/import/check*' => Http::response(['message' => 'ok', 'checks' => []])]);
    $this->server->forceFill(['server_metadata' => ['migration' => [
        'status' => 'running',
        'started_at' => now()->subHour()->toIso8601String(),
    ]]])->save();

    app(ServerTransferMigrator::class)->start($this->server, 'https://8.8.8.8', 'token');

    Queue::assertPushed(MigrateServerToInstanceJob::class, 1);
});

test('start checks the target before it queues the transfer', function (int $status, string $message) {
    Queue::fake();
    Http::fake(['*/api/v1/servers/import/check*' => Http::response(['message' => 'A server with IP/domain 10.0.0.1 already exists on this instance.'], $status)]);

    expect(fn () => app(ServerTransferMigrator::class)->start($this->server, 'https://8.8.8.8', 'wrong-token'))
        ->toThrow(RuntimeException::class, $message);

    Queue::assertNothingPushed();
    $migration = data_get($this->server->fresh()->server_metadata, 'migration');
    expect($migration['status'])->toBe('failed')
        ->and($migration['phase'])->toBe('checks')
        ->and(collect($migration['log'])->last())->toMatchArray(['level' => 'error'])
        ->and(collect($migration['log'])->where('level', 'error')->pluck('message')->implode("\n"))->toContain('FAILED: ')->toContain($message);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'server_uuid='.$this->server->uuid)
        && $request->hasHeader('Authorization', 'Bearer wrong-token'));
})->with([
    'invalid token' => [401, 'does not accept the API token'],
    'token without write permission' => [403, 'write permission'],
    'conflict on the target' => [422, 'already exists on this instance'],
    'target without server transfer' => [404, 'does not support server transfer'],
]);

test('start fails at once when the target cannot be reached', function () {
    Queue::fake();
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    expect(fn () => app(ServerTransferMigrator::class)->start($this->server, 'https://8.8.8.8', 'token'))
        ->toThrow(RuntimeException::class, 'This instance cannot reach the target instance');

    Queue::assertNothingPushed();
});

test('migrate stops waiting for a timed out import when the target rejects the token', function () {
    Http::fake(function ($request) {
        if (str_ends_with($request->url(), '/api/v1/servers/import')) {
            return Http::response('Gateway Timeout', 504);
        }

        return Http::response(['message' => 'Unauthenticated.'], 401);
    });

    expect(fn () => app(ServerTransferMigrator::class)->migrate($this->server, 'https://8.8.8.8', 'token'))
        ->toThrow(RuntimeException::class, 'Target import failed (HTTP 504)');

    Sleep::assertSleptTimes(1);
    expect($this->server->fresh()->isTransferredAway())->toBeFalse();
});

test('start logs every check as OK and the queued transfer', function () {
    Queue::fake();
    Http::fake(['*/api/v1/servers/import/check*' => Http::response([
        'message' => 'ok',
        'checks' => [
            ['label' => 'no server with the same IP exists', 'ok' => true, 'message' => null],
            ['label' => 'the target can reach the SSH port of the server (10.0.0.1:22)', 'ok' => true, 'message' => null],
        ],
    ])]);

    app(ServerTransferMigrator::class)->start($this->server, 'https://8.8.8.8', 'token');

    $log = collect(data_get($this->server->fresh()->server_metadata, 'migration.log'))->pluck('message');
    expect($log)->toContain('OK: the server is not the Coolify host.')
        ->toContain('OK: this instance manages the server.')
        ->toContain('OK: no deployment is queued or running on the server.')
        ->toContain('OK: this instance can connect to the server over SSH.')
        ->toContain('OK: the target instance at https://8.8.8.8 can be reached.')
        ->toContain('OK: the target instance accepts the API token.')
        ->toContain('OK: on the target: the target can reach the SSH port of the server (10.0.0.1:22).')
        ->and($log->last())->toBe('All checks passed. The transfer is queued.');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'schema_version=') && str_contains($request->url(), 'port='));
});

test('start runs every check and logs which are OK and which failed', function () {
    Queue::fake();
    Process::fake(['*' => Process::result(errorOutput: 'Connection refused', exitCode: 255)]);
    Http::fake(['*/api/v1/servers/import/check*' => Http::response([
        'message' => 'A server with IP/domain 10.0.0.1 already exists on this instance.',
        'checks' => [
            ['label' => 'the team can have one more server', 'ok' => true, 'message' => null],
            ['label' => 'no server with the same IP exists', 'ok' => false, 'message' => 'A server with IP/domain 10.0.0.1 already exists on this instance.'],
        ],
    ], 422)]);

    expect(fn () => app(ServerTransferMigrator::class)->start($this->server, 'https://8.8.8.8', 'token'))
        ->toThrow(RuntimeException::class, '2 of 10 checks failed');

    $log = collect(data_get($this->server->fresh()->server_metadata, 'migration.log'));
    expect($log->where('level', 'success')->pluck('message'))->toContain('OK: the target instance accepts the API token.')
        ->toContain('OK: on the target: the team can have one more server.')
        ->and($log->where('level', 'error')->pluck('message')->implode("\n"))
        ->toContain('FAILED: this instance can connect to the server over SSH.')
        ->toContain('FAILED: on the target: no server with the same IP exists. A server with IP/domain 10.0.0.1 already exists')
        ->and($log->last()['message'])->toContain('2 check(s) failed');
    Queue::assertNothingPushed();
});

test('start reports the target checks as not checked when the token is rejected', function () {
    Queue::fake();
    Http::fake(['*/api/v1/servers/import/check*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    expect(fn () => app(ServerTransferMigrator::class)->start($this->server, 'https://8.8.8.8', 'token'))
        ->toThrow(RuntimeException::class);

    $log = collect(data_get($this->server->fresh()->server_metadata, 'migration.log'))->pluck('message');
    expect($log)->toContain('OK: the target instance supports server transfer.')
        ->toContain('NOT CHECKED: the checks on the target instance. They did not run, because "the target instance accepts the API token" failed.');
});

test('the migrate job logs each step of a finished transfer', function () {
    Http::fake([
        'https://8.8.8.8/api/v1/servers/import' => Http::response(['server_uuid' => $this->server->uuid, 'claimed' => true], 201),
    ]);

    (new MigrateServerToInstanceJob($this->server, 'https://8.8.8.8', 'token'))
        ->handle(app(ServerTransferMigrator::class));

    $log = collect(data_get($this->server->fresh()->server_metadata, 'migration.log'));
    expect($log->pluck('message')->first())->toBe('Exporting the server configuration and its resources.')
        ->and($log->pluck('message')->implode("\n"))->toContain('Sending it to https://8.8.8.8')
        ->toContain('manages it now')
        ->and($log->last())->toMatchArray(['level' => 'success', 'message' => 'Transfer finished. The target instance manages the server now.']);
});

test('the migrate job logs each attempt to read the result of a timed out import', function () {
    Http::fake(function ($request) {
        if (str_ends_with($request->url(), '/api/v1/servers/import')) {
            return Http::response('Gateway Timeout', 504);
        }

        return Http::response(['message' => 'Server not found.'], 404);
    });

    (new MigrateServerToInstanceJob($this->server, 'https://8.8.8.8', 'token'))
        ->handle(app(ServerTransferMigrator::class));

    $messages = collect(data_get($this->server->fresh()->server_metadata, 'migration.log'))->pluck('message');
    expect($messages->filter(fn ($message) => str_starts_with($message, 'Asking the target for the import result')))->toHaveCount(ServerTransferMigrator::STATUS_PROBE_ATTEMPTS)
        ->and($messages->last())->toStartWith('Transfer failed: Target import failed (HTTP 504)');
});
