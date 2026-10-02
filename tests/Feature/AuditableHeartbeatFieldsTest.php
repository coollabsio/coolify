<?php

use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\StandaloneSqlite;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();
    Server::flushIdentityMap();
    Log::spy();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
});

function createAuditedResourceForHeartbeatTest(string $model, Environment $environment): Model
{
    if ($model === Application::class) {
        return Application::factory()->create(['environment_id' => $environment->id]);
    }

    $passwords = [
        StandalonePostgresql::class => ['postgres_password' => 'review-fix-e-password'],
        StandaloneMysql::class => ['mysql_root_password' => 'review-fix-e-password', 'mysql_password' => 'review-fix-e-password'],
        StandaloneMariadb::class => ['mariadb_root_password' => 'review-fix-e-password', 'mariadb_password' => 'review-fix-e-password'],
        StandaloneMongodb::class => ['mongo_initdb_root_password' => 'review-fix-e-password'],
        StandaloneRedis::class => [],
        StandaloneKeydb::class => ['keydb_password' => 'review-fix-e-password'],
        StandaloneDragonfly::class => ['dragonfly_password' => 'review-fix-e-password'],
        StandaloneClickhouse::class => ['clickhouse_admin_password' => 'review-fix-e-password'],
        StandaloneSqlite::class => [],
    ];

    return $model::create([
        'uuid' => fake()->uuid(),
        'name' => 'review-fix-e-database',
        'status' => 'exited',
        'environment_id' => $environment->id,
        'destination_type' => Server::class,
        'destination_id' => 0,
        ...$passwords[$model],
    ]);
}

test('container heartbeat and restart tracking writes are not audited as user changes', function (string $model) {
    $resource = createAuditedResourceForHeartbeatTest($model, $this->environment);
    AuditEvent::query()->delete();

    $resource->update(['status' => 'running:healthy', 'last_online_at' => now()]);
    $resource->update(['restart_count' => 3, 'last_restart_at' => now(), 'last_restart_type' => 'crash']);
    $resource->update(['config_hash' => 'review-fix-e-hash']);
    $resource->update($model === Application::class
        ? ['container_present' => false, 'restart_limit_reached' => true]
        : ['started_at' => now()]);

    expect(AuditEvent::query()->count())->toBe(0);

    $resource->update(['name' => 'review-fix-e-renamed', 'restart_count' => 0]);

    expect(AuditEvent::query()->sole()->metadata['changed_fields'])->toBe(['name']);
})->with([
    Application::class,
    StandalonePostgresql::class,
    StandaloneMysql::class,
    StandaloneMariadb::class,
    StandaloneMongodb::class,
    StandaloneRedis::class,
    StandaloneKeydb::class,
    StandaloneDragonfly::class,
    StandaloneClickhouse::class,
    StandaloneSqlite::class,
]);

test('application dns check results are not audited as user changes', function () {
    $application = Application::factory()->create(['environment_id' => $this->environment->id]);
    AuditEvent::query()->delete();

    $application->update(['domain_dns_statuses' => ['https://example.com' => ['status' => 'ok']], 'custom_healthcheck_found' => true]);

    expect(AuditEvent::query()->count())->toBe(0);
});

test('server heartbeat and check state writes are not audited as user changes', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    AuditEvent::query()->delete();

    $server->sentinelHeartbeat();
    $server->update([
        'sentinel_waiting_since' => now(),
        'unreachable_count' => 2,
        'high_disk_usage_notification_sent' => true,
        'log_drain_notification_sent' => true,
        'validation_logs' => 'review-fix-e-validation',
        'is_validating' => true,
        'detected_traefik_version' => 'v3.1.0',
        'traefik_outdated_info' => ['current' => 'v3.1.0'],
    ]);

    expect(AuditEvent::query()->count())->toBe(0);

    $server->update(['description' => 'review-fix-e-description']);

    expect(AuditEvent::query()->sole()->metadata['changed_fields'])->toBe(['description']);
});
