<?php

use App\Jobs\MigrateServerToInstanceJob;
use App\Jobs\ValidateAndInstallServerJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Services\ServerTransfer\ServerTransferClaimer;
use App\Services\ServerTransfer\ServerTransferExporter;
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
    $project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Shop']);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);
    $this->environment = $environment;
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
    'token without the write and deploy permissions' => [403, 'write and deploy permissions'],
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
        ->toThrow(RuntimeException::class, '2 of 13 checks failed');

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

test('the migrate job stores the post-transfer checklist with the target URL', function () {
    Http::fake([
        'https://8.8.8.8/api/v1/servers/import' => Http::response(['server_uuid' => $this->server->uuid, 'claimed' => true], 201),
    ]);
    $exporter = app(ServerTransferExporter::class);
    $fakeExporter = Mockery::mock(ServerTransferExporter::class);
    $fakeExporter->shouldReceive('export')->once()->andReturnUsing(fn (Server $server, bool $includeSensitive = true) => [
        ...$exporter->export($server, $includeSensitive),
        'post_transfer_checklist' => [
            'Set the webhook URL of GitHub App deploy-bot to {target_url}/webhooks/source/github/events.',
            ['not a step'],
            'Point the DNS record of shop.example.com to the server.',
        ],
    ]);
    app()->instance(ServerTransferExporter::class, $fakeExporter);

    (new MigrateServerToInstanceJob($this->server, 'https://8.8.8.8', 'token'))
        ->handle(app(ServerTransferMigrator::class));

    $migration = data_get($this->server->fresh()->server_metadata, 'migration');
    expect($migration['status'])->toBe('succeeded')
        ->and($migration['checklist'])->toBe([
            'Set the webhook URL of GitHub App deploy-bot to https://8.8.8.8/webhooks/source/github/events.',
            'Point the DNS record of shop.example.com to the server.',
        ]);
});

test('the migrate job stores the checklist of the target, which has the public URL of the target', function () {
    Http::fake([
        'https://8.8.8.8/api/v1/servers/import' => Http::response([
            'server_uuid' => $this->server->uuid,
            'claimed' => true,
            'checklist' => ['Set the webhook URL of GitHub App deploy-bot to https://coolify-b.example.com/webhooks/source/github/events.'],
        ], 201),
    ]);

    (new MigrateServerToInstanceJob($this->server, 'https://8.8.8.8', 'token'))
        ->handle(app(ServerTransferMigrator::class));

    expect(data_get($this->server->fresh()->server_metadata, 'migration.checklist'))
        ->toBe(['Set the webhook URL of GitHub App deploy-bot to https://coolify-b.example.com/webhooks/source/github/events.']);
});

test('start notes projects that also have resources on other servers', function (int $checkStatus, bool $queued) {
    Queue::fake();
    Http::fake(['*/api/v1/servers/import/check*' => Http::response(['message' => 'ok', 'checks' => []], $checkStatus)]);
    $otherServer = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->privateKey->id, 'ip' => '10.88.0.11']);
    $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->firstOrFail();
    foreach (['web', 'worker'] as $name) {
        Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $otherDestination->id,
            'destination_type' => $otherDestination->getMorphClass(),
            'name' => $name,
        ]);
    }
    // A project with resources only on the other server is not affected by the transfer.
    $otherProject = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Blog']);
    Application::factory()->create([
        'environment_id' => $otherProject->environments()->first()->id,
        'destination_id' => $otherDestination->id,
        'destination_type' => $otherDestination->getMorphClass(),
    ]);

    try {
        app(ServerTransferMigrator::class)->start($this->server, 'https://8.8.8.8', 'token');
    } catch (RuntimeException) {
    }

    $notes = collect(data_get($this->server->fresh()->server_metadata, 'migration.log'))
        ->filter(fn (array $entry) => str_starts_with($entry['message'], 'NOTE: '));
    expect($notes->pluck('message')->all())->toBe([
        "NOTE: project 'Shop' also has 2 resources on other servers. They stay on this instance; only the resources of this server are transferred.",
    ])
        ->and($notes->first()['level'])->toBe('info');
    $queued ? Queue::assertPushed(MigrateServerToInstanceJob::class, 1) : Queue::assertNothingPushed();
})->with([
    'checks pass' => [200, true],
    'a check fails' => [401, false],
]);

test('start adds no note when the projects have no resources on other servers', function () {
    Queue::fake();
    Http::fake(['*/api/v1/servers/import/check*' => Http::response(['message' => 'ok', 'checks' => []])]);

    app(ServerTransferMigrator::class)->start($this->server, 'https://8.8.8.8', 'token');

    $messages = collect(data_get($this->server->fresh()->server_metadata, 'migration.log'))->pluck('message');
    expect($messages->filter(fn (string $message) => str_starts_with($message, 'NOTE: ')))->toBeEmpty();
    Queue::assertPushed(MigrateServerToInstanceJob::class, 1);
});

test('the migrate job timeout covers the import request and every status probe', function () {
    $worstCase = ServerTransferMigrator::IMPORT_TIMEOUT_SECONDS
        + ServerTransferMigrator::STATUS_PROBE_ATTEMPTS
        * (ServerTransferMigrator::STATUS_PROBE_INTERVAL_SECONDS + ServerTransferMigrator::STATUS_REQUEST_TIMEOUT_SECONDS);
    $job = new MigrateServerToInstanceJob($this->server, 'https://8.8.8.8', 'token');

    // The rest of the timeout is for the export and the database writes.
    expect($job->timeout)->toBeGreaterThanOrEqual($worstCase + 300)
        ->and(ServerTransferMigrator::STALE_MIGRATION_SECONDS)->toBeGreaterThan($job->timeout);
});

function stopMigrateJobAfterExport(Server $server): void
{
    $metadata = $server->fresh()->server_metadata ?? [];
    $metadata['migration'] = ['status' => 'running', 'target_url' => 'https://8.8.8.8', 'export_id' => 'export-1', 'log' => []];
    $server->forceFill(['server_metadata' => $metadata])->save();

    (new MigrateServerToInstanceJob($server, 'https://8.8.8.8', 'token'))
        ->failed(new RuntimeException('The job has timed out.'));
}

test('a stopped migrate job completes the transfer when the target manages the server', function () {
    Http::fake(['*' => Http::response(['transfer' => ['status' => 'claimed', 'export_id' => 'export-1']])]);

    stopMigrateJobAfterExport($this->server);

    $server = $this->server->fresh();
    expect(data_get($server->server_metadata, 'migration.status'))->toBe('succeeded')
        ->and($server->isTransferredAway())->toBeTrue();
});

test('a stopped migrate job records an unknown result when the target cannot be asked', function () {
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    stopMigrateJobAfterExport($this->server);

    $server = $this->server->fresh();
    expect(data_get($server->server_metadata, 'migration.status'))->toBe('unknown')
        ->and(data_get($server->server_metadata, 'migration.error'))->toContain("/api/v1/servers/{$server->uuid}/transfer/complete")
        ->and($server->isTransferredAway())->toBeFalse();
});

test('a stopped migrate job records a failure when the target does not have the export', function (array $body, int $status) {
    Http::fake(['*' => Http::response($body, $status)]);

    stopMigrateJobAfterExport($this->server);

    $server = $this->server->fresh();
    expect(data_get($server->server_metadata, 'migration.status'))->toBe('failed')
        ->and($server->isTransferredAway())->toBeFalse();
})->with([
    'not found' => [['message' => 'Server not found.'], 404],
    'other export' => [['transfer' => ['status' => 'claimed', 'export_id' => 'export-0']], 200],
]);

test('a migrate job stopped before the export records a failure without asking the target', function () {
    Http::fake();

    (new MigrateServerToInstanceJob($this->server, 'https://8.8.8.8', 'token'))
        ->failed(new RuntimeException('The job has timed out.'));

    expect(data_get($this->server->fresh()->server_metadata, 'migration.status'))->toBe('failed');
    Http::assertNothingSent();
});

test('migrate saves the export ID before it sends the bundle', function () {
    $savedExportId = null;
    Http::fake(function ($request) use (&$savedExportId) {
        $savedExportId = data_get($this->server->fresh()->server_metadata, 'migration.export_id');

        return Http::response(['server_uuid' => $this->server->uuid, 'claimed' => true], 201);
    });

    $result = app(ServerTransferMigrator::class)->migrate($this->server, 'https://8.8.8.8', 'token');

    expect($savedExportId)->toBe($result['export_id']);
});

test('transfer back asks the managing instance to transfer the archive here with a short-lived token of its team', function () {
    $user = User::factory()->create();
    $this->team->members()->attach($user->id, ['role' => 'owner']);
    app(ServerTransferClaimer::class)->markTransferred($this->server, targetInstanceUrl: 'https://8.8.8.8');
    Http::fake(['https://8.8.8.8/api/v1/servers/*/migrate' => Http::response(['migration' => ['status' => 'running']], 202)]);

    app(ServerTransferMigrator::class)->requestTransferBack($this->server->fresh(), $user, 'https://8.8.8.8', 'token-of-b', 'https://coolify-a.test');

    $token = $user->tokens()->sole();
    expect((int) $token->team_id)->toBe($this->team->id)
        ->and($token->expires_at->isFuture())->toBeTrue()
        ->and(data_get($this->server->fresh()->server_metadata, 'transfer.transfer_back.from'))->toBe('https://8.8.8.8');
    $importToken = null;
    Http::assertSent(function ($request) use ($token, &$importToken) {
        $importToken = (string) $request['target_token'];

        return $request->url() === "https://8.8.8.8/api/v1/servers/{$this->server->uuid}/migrate"
            && $request->hasHeader('Authorization', 'Bearer token-of-b')
            && $request['target_url'] === 'https://coolify-a.test'
            && str_starts_with($importToken, $token->id.'|');
    });
    // The other instance runs the import checks and the import with this token. An empty bundle fails only the validation.
    $this->withHeaders(['Authorization' => "Bearer {$importToken}", 'Accept' => 'application/json'])
        ->postJson('/api/v1/servers/import', ['bundle' => []])
        ->assertStatus(422);
});

test('a refused transfer back deletes the token and shows the reason of the other instance', function () {
    $user = User::factory()->create();
    $this->team->members()->attach($user->id, ['role' => 'owner']);
    app(ServerTransferClaimer::class)->markTransferred($this->server, targetInstanceUrl: 'https://8.8.8.8');
    Http::fake(['*' => Http::response(['message' => '1 of 13 checks failed: no server with the same IP exists.'], 422)]);

    expect(fn () => app(ServerTransferMigrator::class)->requestTransferBack($this->server->fresh(), $user, 'https://8.8.8.8', 'token-of-b', 'https://coolify-a.test'))
        ->toThrow(RuntimeException::class, 'no server with the same IP exists');

    expect($user->tokens()->count())->toBe(0)
        ->and(data_get($this->server->fresh()->server_metadata, 'transfer.transfer_back'))->toBeNull();
});

test('start notes system-wide git sources that the target does not have', function (bool $noted) {
    Queue::fake();
    $systemApp = GithubApp::create([
        'name' => 'Coolify GitHub', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com',
        'team_id' => 0, 'is_system_wide' => true, 'is_public' => false,
    ]);
    Application::where('name', 'app-on-server')->update(['source_type' => GithubApp::class, 'source_id' => $systemApp->id]);
    Http::fake(['*/api/v1/servers/import/check*' => Http::response(['message' => 'ok', 'checks' => [], 'missing_system_sources' => ['github' => $noted ? [$systemApp->uuid] : [], 'gitlab' => []]])]);

    app(ServerTransferMigrator::class)->start($this->server, 'https://8.8.8.8', 'token');

    $notes = collect(data_get($this->server->fresh()->server_metadata, 'migration.log'))->pluck('message')
        ->filter(fn (string $message) => str_contains($message, 'system-wide'));
    expect($notes->isNotEmpty())->toBe($noted);
    if ($noted) {
        expect($notes->first())->toBe("NOTE: the system-wide GitHub App 'Coolify GitHub' of this instance is not transferred, and the target has no matching one. These applications need a new git source on the target: app-on-server. The finish list on the target shows them.");
    }
    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), "system_github_apps[0]={$systemApp->uuid}"));
    Queue::assertPushed(MigrateServerToInstanceJob::class, 1);
})->with([
    'the target has no matching App' => [true],
    'the target has the App' => [false],
]);

test('a transfer back token has only the import abilities, expires within an hour, and survives the claim', function () {
    $user = User::factory()->create();
    $this->team->members()->attach($user->id, ['role' => 'owner']);
    app(ServerTransferClaimer::class)->markTransferred($this->server, targetInstanceUrl: 'https://8.8.8.8');
    Http::fake(['https://8.8.8.8/api/v1/servers/*/migrate' => Http::response(['migration' => ['status' => 'running']], 202)]);

    app(ServerTransferMigrator::class)->requestTransferBack($this->server->fresh(), $user, 'https://8.8.8.8', 'token-of-b', 'https://coolify-a.test');

    $token = $user->tokens()->sole();
    expect($token->abilities)->toBe(['write', 'deploy'])
        ->and($token->expires_at->lte(now()->addHour()))->toBeTrue();

    // The other instance still checks the import status with the token after the import claims the server.
    Queue::fake();
    app(ServerTransferClaimer::class)->claim($this->server->fresh());

    expect($user->tokens()->count())->toBe(1);
});

test('start fails while a backup or a scheduled task runs on the server', function (string $kind) {
    Queue::fake();
    Http::fake(['*/api/v1/servers/import/check*' => Http::response(['message' => 'ok', 'checks' => []])]);
    $application = Application::where('name', 'app-on-server')->sole();
    if ($kind === 'database backup') {
        $database = StandalonePostgresql::withoutEvents(fn () => StandalonePostgresql::forceCreate([
            'uuid' => new_public_id(), 'name' => 'busy-db', 'postgres_password' => 'secret',
            'environment_id' => $application->environment_id, 'destination_id' => $application->destination_id, 'destination_type' => $application->destination_type,
        ]));
        $backup = ScheduledDatabaseBackup::create([
            'enabled' => true, 'frequency' => '0 0 * * *', 'save_s3' => false, 'team_id' => $this->team->id,
            'database_type' => $database->getMorphClass(), 'database_id' => $database->id,
        ]);
        ScheduledDatabaseBackupExecution::create(['scheduled_database_backup_id' => $backup->id, 'status' => 'running']);
    } else {
        $task = ScheduledTask::factory()->create(['team_id' => $this->team->id, 'application_id' => $application->id]);
        ScheduledTaskExecution::create(['scheduled_task_id' => $task->id, 'status' => 'running']);
    }

    expect(fn () => app(ServerTransferMigrator::class)->start($this->server, 'https://8.8.8.8', 'token'))
        ->toThrow(RuntimeException::class, "1 {$kind}(s) on this server are running");
    Queue::assertNotPushed(MigrateServerToInstanceJob::class);
})->with(['database backup', 'scheduled task']);

test('migrate keeps only an https link to the server on the target', function (string $serverUrl, ?string $kept) {
    Http::fake(['https://8.8.8.8/api/v1/servers/import' => Http::response([
        'server_uuid' => $this->server->uuid, 'claimed' => true, 'warnings' => [], 'server_url' => $serverUrl,
    ], 201)]);

    $result = app(ServerTransferMigrator::class)->migrate($this->server, 'https://8.8.8.8', 'token');

    expect($result['target_server_url'])->toBe($kept);
})->with([
    'https' => ['https://coolify-b.example.com/server/abc', 'https://coolify-b.example.com/server/abc'],
    'javascript' => ['javascript:alert(document.cookie)', null],
    'plain http' => ['http://coolify-b.example.com/server/abc', null],
]);
