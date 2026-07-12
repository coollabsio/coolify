<?php

use App\Livewire\Project\Application\Configuration as ApplicationConfiguration;
use App\Livewire\Project\Application\ServerStatusBadge;
use App\Livewire\Project\Database\Clickhouse\General as ClickhouseGeneral;
use App\Livewire\Project\Database\Clickhouse\StatusInfo as ClickhouseStatusInfo;
use App\Livewire\Project\Database\Dragonfly\General as DragonflyGeneral;
use App\Livewire\Project\Database\Dragonfly\StatusInfo as DragonflyStatusInfo;
use App\Livewire\Project\Database\Import as DatabaseImport;
use App\Livewire\Project\Database\ImportForm as DatabaseImportForm;
use App\Livewire\Project\Database\Keydb\General as KeydbGeneral;
use App\Livewire\Project\Database\Keydb\StatusInfo as KeydbStatusInfo;
use App\Livewire\Project\Database\Mariadb\General as MariadbGeneral;
use App\Livewire\Project\Database\Mariadb\StatusInfo as MariadbStatusInfo;
use App\Livewire\Project\Database\Mongodb\General as MongodbGeneral;
use App\Livewire\Project\Database\Mongodb\StatusInfo as MongodbStatusInfo;
use App\Livewire\Project\Database\Mysql\General as MysqlGeneral;
use App\Livewire\Project\Database\Mysql\StatusInfo as MysqlStatusInfo;
use App\Livewire\Project\Database\Postgresql\General as PostgresqlGeneral;
use App\Livewire\Project\Database\Postgresql\StatusInfo as PostgresqlStatusInfo;
use App\Livewire\Project\Database\Redis\General as RedisGeneral;
use App\Livewire\Project\Database\Redis\StatusInfo as RedisStatusInfo;
use App\Livewire\Project\Service\Configuration as ServiceConfiguration;
use App\Livewire\Project\Service\ResourceCard as ServiceResourceCard;
use App\Livewire\Server\Sentinel;
use App\Livewire\Server\Show;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\StandaloneMysql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

dataset('database-general-forms-without-broadcasts', [
    // Status-derived display moved into a sibling StatusInfo component for each DB,
    // so the form itself takes no broadcast listeners and cannot clobber wire:dirty
    // by absorbing deferred wire:model values during a status-triggered roundtrip.
    RedisGeneral::class,
    PostgresqlGeneral::class,
    MysqlGeneral::class,
    MariadbGeneral::class,
    MongodbGeneral::class,
    KeydbGeneral::class,
    DragonflyGeneral::class,
    ClickhouseGeneral::class,
    DatabaseImportForm::class,
    ServiceConfiguration::class,
    ApplicationConfiguration::class,
]);

dataset('database-status-info-components', [
    RedisStatusInfo::class,
    PostgresqlStatusInfo::class,
    MysqlStatusInfo::class,
    MariadbStatusInfo::class,
    MongodbStatusInfo::class,
    KeydbStatusInfo::class,
    DragonflyStatusInfo::class,
    ClickhouseStatusInfo::class,
]);

dataset('display-only-status-components', [
    RedisStatusInfo::class,
    PostgresqlStatusInfo::class,
    MysqlStatusInfo::class,
    MariadbStatusInfo::class,
    MongodbStatusInfo::class,
    KeydbStatusInfo::class,
    DragonflyStatusInfo::class,
    ClickhouseStatusInfo::class,
    DatabaseImport::class,
    ServiceResourceCard::class,
    ServerStatusBadge::class,
]);

it('does not subscribe the form to status broadcasts when display lives in a sibling', function (string $componentClass) {
    // Regression guard for coolify#6062 / #6354 / #9695:
    // Status broadcasts on the form would trigger a Livewire roundtrip that absorbs
    // deferred wire:model values into the snapshot — clobbering both the typed text
    // (resolved by the earlier refreshStatus fix) and the wire:dirty indicator.
    $listeners = resolveLivewireListeners(app($componentClass));

    expect($listeners)
        ->not->toHaveKey("echo-private:user.{$this->user->id},DatabaseStatusChanged")
        ->not->toHaveKey("echo-private:team.{$this->team->id},ServiceChecked")
        ->not->toHaveKey("echo-private:team.{$this->team->id},ServiceStatusChanged");
})->with('database-general-forms-without-broadcasts');

/**
 * Resolve a Livewire component's listeners regardless of whether the subclass
 * exposes getListeners() publicly or only declares a $listeners array — the
 * HandlesEvents trait keeps getListeners() protected by default.
 */
function resolveLivewireListeners(object $component): array
{
    $method = new ReflectionMethod($component, 'getListeners');
    $method->setAccessible(true);

    return (array) $method->invoke($component);
}

it('auto-refreshes status-info sibling on database status broadcasts', function (string $componentClass) {
    // Status-derived display (connection URLs, SSL gate hint, cert expiry) lives in a sibling
    // Livewire component so it can re-render on broadcasts without touching the form's DOM.
    $listeners = resolveLivewireListeners(app($componentClass));

    expect($listeners)
        ->toHaveKey("echo-private:user.{$this->user->id},DatabaseStatusChanged")
        ->toHaveKey("echo-private:team.{$this->team->id},ServiceChecked");
})->with('database-status-info-components');

it('keeps realtime status listeners on display-only components instead of form owners', function (string $componentClass) {
    $listeners = resolveLivewireListeners(app($componentClass));

    expect($listeners)->not->toBeEmpty();
})->with('display-only-status-components');

it('refreshes a service resource card without refreshing the service configuration form owner', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $service = Service::create([
        'name' => 'status-card-service',
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => 'services: {}',
    ]);
    $serviceApplication = ServiceApplication::create([
        'service_id' => $service->id,
        'name' => 'web',
        'image' => 'nginx:latest',
        'status' => 'exited:unhealthy',
    ]);
    $parameters = [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'service_uuid' => $service->uuid,
    ];

    $component = Livewire::test(ServiceResourceCard::class, [
        'service' => $service,
        'resource' => $serviceApplication,
        'parameters' => $parameters,
    ]);

    $serviceApplication->fill(['status' => 'running:healthy'])->save();

    $component->call('refreshResource');

    expect($component->instance()->resource->status)->toBe('running:healthy');
});

it('refreshes database import status from stored resource identity after the route context is gone', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $database = StandaloneMysql::create([
        'name' => 'import-status-mysql',
        'image' => 'mysql:8',
        'mysql_root_password' => 'password',
        'mysql_user' => 'coolify',
        'mysql_password' => 'password',
        'mysql_database' => 'coolify',
        'status' => 'exited:unhealthy',
        'is_log_drain_enabled' => false,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $component = app(DatabaseImport::class);
    $component->resourceId = $database->id;
    $component->resourceType = StandaloneMysql::class;

    $database->fill(['status' => 'running:healthy'])->save();

    $component->refreshStatus();

    expect($component->resourceStatus)->toBe('running:healthy');
});

it('reloads the mysql status-info model when refresh is called so ssl controls follow the latest status', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $database = StandaloneMysql::create([
        'name' => 'test-mysql',
        'image' => 'mysql:8',
        'mysql_root_password' => 'password',
        'mysql_user' => 'coolify',
        'mysql_password' => 'password',
        'mysql_database' => 'coolify',
        'status' => 'exited:unhealthy',
        'enable_ssl' => true,
        'is_log_drain_enabled' => false,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $component = Livewire::test(MysqlStatusInfo::class, ['database' => $database])
        ->assertDontSee('Database should be stopped to change this settings.');

    $database->fill(['status' => 'running:healthy'])->save();

    $component->call('refresh')
        ->assertSee('Database should be stopped to change this settings.');
});

it('does not clobber server form text inputs when sentinel restarts', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'persisted-server-name',
    ]);

    $component = Livewire::test(Sentinel::class, ['server_uuid' => $server->uuid])
        ->set('sentinelToken', 'user-was-typing-this-token');

    $component->call('handleSentinelRestarted', ['serverUuid' => $server->uuid]);

    expect($component->get('sentinelToken'))->toBe('user-was-typing-this-token');
});

it('does not clobber server form text inputs when server validation completes', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'persisted-server-name',
    ]);

    $component = Livewire::test(Show::class, ['server_uuid' => $server->uuid])
        ->set('name', 'user-was-typing-here')
        ->set('ip', '203.0.113.42');

    $component->call('handleServerValidated', ['serverUuid' => $server->uuid]);

    expect($component->get('name'))->toBe('user-was-typing-here')
        ->and($component->get('ip'))->toBe('203.0.113.42');
});

it('shows the redis ssl gate hint after the sibling is refreshed', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $database = StandaloneRedis::create([
        'name' => 'test-redis',
        'image' => 'redis:7',
        'redis_password' => 'password',
        'redis_username' => 'default',
        'status' => 'exited:unhealthy',
        'enable_ssl' => true,
        'is_log_drain_enabled' => false,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $component = Livewire::test(RedisStatusInfo::class, ['database' => $database])
        ->assertDontSee('Database should be stopped to change this settings.');

    $database->fill(['status' => 'running:healthy'])->save();

    $component->call('refresh')
        ->assertSee('Database should be stopped to change this settings.');
});
