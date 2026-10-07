<?php

use App\Actions\Database\StartPostgresql;
use App\Enums\ProcessStatus;
use App\Events\DatabaseStatusChanged;
use App\Events\ServiceStartFinished;
use App\Events\ServiceStatusChanged;
use App\Jobs\DatabaseStartJob;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Services\ContainerStatusAggregator;
use App\Services\ResourceStatusRefresher;
use App\Support\ResourceStartActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config()->set('cache.default', 'array');
    config()->set('constants.ssh.mux_enabled', false);
    Sleep::fake();

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->database = StandalonePostgresql::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'db',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'db',
        'image' => 'postgres:17',
        'status' => 'exited',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
});

/**
 * One line of `docker container inspect --format '{{json .}}'` output.
 */
function inspectedContainer(string $name, string $state, ?string $health = null, array $labels = []): string
{
    return json_encode([
        'Name' => "/{$name}",
        'State' => array_filter(['Status' => $state, 'Health' => $health ? ['Status' => $health] : null]),
        'Config' => ['Labels' => $labels],
    ]);
}

/**
 * Answer each SSH command with the next output in the list (the last one repeats).
 *
 * @param  list<string>  $outputs
 */
function fakeInspectOutputs(array $outputs): void
{
    $sequence = Process::sequence()->dontFailWhenEmpty();
    foreach ($outputs as $output) {
        $sequence->push(Process::result(output: $output));
    }
    Process::fake(['*' => $sequence->whenEmpty(Process::result(output: end($outputs)))]);
}

test('a container status has the same format for every status writer', function (string $state, ?string $health, string $expected) {
    expect(ContainerStatusAggregator::containerStatus(['State' => ['Status' => $state, 'Health' => $health ? ['Status' => $health] : null]]))
        ->toBe($expected);
})->with([
    'healthy' => ['running', 'healthy', 'running:healthy'],
    'no health check' => ['running', null, 'running:unknown'],
    'restarting' => ['restarting', null, 'restarting:unknown'],
    'exited' => ['exited', 'unhealthy', 'exited'],
]);

test('it stores the status of a started database', function () {
    fakeInspectOutputs([inspectedContainer($this->database->uuid, 'running', 'healthy')]);

    app(ResourceStatusRefresher::class)->refreshDatabase($this->database);

    expect($this->database->fresh()->status)->toBe('running:healthy');
    Sleep::assertNeverSlept();
});

test('it inspects the database again while its health check is starting', function () {
    fakeInspectOutputs([
        inspectedContainer($this->database->uuid, 'running', 'starting'),
        inspectedContainer($this->database->uuid, 'running', 'healthy'),
    ]);

    app(ResourceStatusRefresher::class)->refreshDatabase($this->database);

    expect($this->database->fresh()->status)->toBe('running:healthy');
    Sleep::assertSleptTimes(1);
});

test('it stops waiting for the health check after the limit', function () {
    fakeInspectOutputs([inspectedContainer($this->database->uuid, 'running', 'starting')]);

    app(ResourceStatusRefresher::class)->refreshDatabase($this->database);

    expect($this->database->fresh()->status)->toBe('running:starting');
    Sleep::assertSleptTimes(ResourceStatusRefresher::HEALTH_WAIT_ATTEMPTS - 1);
});

test('it keeps the stored status when the database container is not found', function () {
    fakeInspectOutputs(['']);
    $storedStatus = $this->database->fresh()->status;

    app(ResourceStatusRefresher::class)->refreshDatabase($this->database);

    expect($this->database->fresh()->status)->toBe($storedStatus);
});

test('the database start job stores the running status before it broadcasts', function () {
    Event::fake([DatabaseStatusChanged::class]);
    fakeInspectOutputs([inspectedContainer($this->database->uuid, 'running', 'healthy')]);
    $activity = activity()->withProperties([
        'status' => ProcessStatus::QUEUED->value,
        'type_uuid' => $this->database->uuid,
        'operation' => ResourceStartActivity::DATABASE_START_OPERATION,
    ])->log('[]');
    StartPostgresql::shouldRun()->andReturnUsing(function ($database, $activity) {
        $activity->properties = $activity->properties->merge(['status' => ProcessStatus::FINISHED->value]);
        $activity->save();

        return $activity;
    });

    (new DatabaseStartJob($this->database->getMorphClass(), $this->database->id, $this->team->id, $activity->id, null))->handle();

    expect($this->database->fresh()->status)->toBe('running:healthy');
    Event::assertDispatched(DatabaseStatusChanged::class);
});

test('a finished service start stores the status of each service part and broadcasts it', function () {
    Event::fake([ServiceStatusChanged::class]);
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  app:\n    image: nginx\n  db:\n    image: postgres\n",
    ]);
    $app = ServiceApplication::create(['name' => 'app', 'service_id' => $service->id, 'status' => 'exited']);
    $db = ServiceDatabase::create(['name' => 'db', 'service_id' => $service->id, 'status' => 'exited']);
    $labels = fn (string $subType, string $subUuid, string $name) => [
        'coolify.serviceUuid' => $service->uuid,
        'coolify.service.subType' => $subType,
        'coolify.service.subUuid' => $subUuid,
        'com.docker.compose.service' => $name,
    ];
    fakeInspectOutputs([implode("\n", [
        inspectedContainer("app-{$service->uuid}", 'running', null, $labels('application', $app->uuid, 'app')),
        inspectedContainer("db-{$service->uuid}", 'running', 'healthy', $labels('database', $db->uuid, 'db')),
    ])]);

    event(new ServiceStartFinished($service->id));

    expect($app->fresh()->status)->toBe('running:unknown')
        ->and($db->fresh()->status)->toBe('running:healthy');
    Event::assertDispatched(ServiceStatusChanged::class, fn (ServiceStatusChanged $event) => $event->teamId === $this->team->id);
});
