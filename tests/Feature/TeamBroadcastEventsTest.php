<?php

use App\Actions\Database\StopDatabaseProxy;
use App\Events\ApplicationConfigurationChanged;
use App\Events\ApplicationStatusChanged;
use App\Events\BackupCreated;
use App\Events\CloudflareTunnelConfigured;
use App\Events\DatabaseProxyStopped;
use App\Events\FileStorageChanged;
use App\Events\ProxyStatusChangedUI;
use App\Events\ScheduledTaskDone;
use App\Events\SentinelRestarted;
use App\Events\SentinelSynchronized;
use App\Events\ServerPackageUpdated;
use App\Events\ServerValidated;
use App\Events\ServiceChecked;
use App\Events\ServiceStatusChanged;
use App\Events\TestEvent;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.store' => 'array', 'cache.default' => 'array']);
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0]));
    $this->team = Team::factory()->create();
});

function teamBroadcastChannelNames(object $event): array
{
    return array_map(fn (PrivateChannel $channel) => $channel->name, $event->broadcastOn());
}

dataset('team broadcast events', [
    ApplicationConfigurationChanged::class,
    ApplicationStatusChanged::class,
    BackupCreated::class,
    CloudflareTunnelConfigured::class,
    DatabaseProxyStopped::class,
    FileStorageChanged::class,
    ProxyStatusChangedUI::class,
    ScheduledTaskDone::class,
    ServerPackageUpdated::class,
    ServerValidated::class,
    ServiceChecked::class,
    ServiceStatusChanged::class,
    TestEvent::class,
]);

it('broadcasts on the given team channel, not the session team', function (string $eventClass) {
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $user->teams()->attach($otherTeam, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $otherTeam]);

    expect(teamBroadcastChannelNames(new $eventClass($this->team->id)))
        ->toBe(["private-team.{$this->team->id}"]);
})->with('team broadcast events');

it('does not broadcast without a team', function (string $eventClass) {
    expect((new $eventClass(null))->broadcastOn())->toBe([]);
})->with('team broadcast events');

it('broadcasts sentinel events on the server team channel', function () {
    $server = new Server(['team_id' => $this->team->id]);
    $server->uuid = 'server-uuid';

    expect(teamBroadcastChannelNames(new SentinelRestarted($server, '1.0.0')))
        ->toBe(["private-team.{$this->team->id}"])
        ->and(teamBroadcastChannelNames(new SentinelSynchronized($server)))
        ->toBe(["private-team.{$this->team->id}"]);
});

it('announces a stopped database proxy to the team that owns the server', function () {
    Event::fake([DatabaseProxyStopped::class]);
    Process::fake(fn () => Process::result(output: 'OK'));

    $keyId = DB::table('private_keys')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Key',
        'private_key' => Crypt::encryptString('test-key'),
        'team_id' => $this->team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $keyId]);
    $destination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::firstOrCreate(
        ['server_id' => $server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
    ));
    $project = Project::create(['uuid' => (string) Str::uuid(), 'name' => 'Project', 'team_id' => $this->team->id]);
    $environment = $project->environments()->first() ?? Environment::factory()->create(['project_id' => $project->id]);
    $database = StandalonePostgresql::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'db',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'db',
        'image' => 'postgres:17',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    // Runs as a queued job in production: there is no authenticated user.
    StopDatabaseProxy::run($database);

    Event::assertDispatched(DatabaseProxyStopped::class, fn (DatabaseProxyStopped $event) => $event->teamId === $this->team->id);
});
