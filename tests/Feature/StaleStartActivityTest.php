<?php

use App\Enums\ActivityTypes;
use App\Enums\ProcessStatus;
use App\Livewire\Project\Database\Heading as DatabaseHeading;
use App\Livewire\Project\Service\Heading as ServiceHeading;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Support\ResourceStartActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::firstOrCreate(
        ['server_id' => $this->server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'docker']
    );
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
    $this->service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'server_id' => $this->server->id,
    ]);
});

function startActivity(string $typeUuid, ProcessStatus $status, ?string $operation = null, int $minutesSinceLastUpdate = 0): Activity
{
    $activity = activity()
        ->withProperties(array_filter([
            'type' => ActivityTypes::INLINE->value,
            'type_uuid' => $typeUuid,
            'status' => $status->value,
            'operation' => $operation,
        ]))
        ->event(ActivityTypes::INLINE->value)
        ->log('[]');

    Activity::query()->whereKey($activity->id)->update([
        'created_at' => now()->subMinutes($minutesSinceLastUpdate),
        'updated_at' => now()->subMinutes($minutesSinceLastUpdate),
    ]);

    return $activity->refresh();
}

function databaseHeadingFor(StandalonePostgresql $database): DatabaseHeading
{
    $heading = new DatabaseHeading;
    $heading->database = $database;

    return $heading;
}

function serviceHeadingFor(Service $service): ServiceHeading
{
    $heading = new ServiceHeading;
    $heading->service = $service;

    return $heading;
}

dataset('blocking start activities', [
    'freshly queued' => [ProcessStatus::QUEUED, 0],
    'queued for 9 minutes' => [ProcessStatus::QUEUED, 9],
    'in progress with recent output' => [ProcessStatus::IN_PROGRESS, 0],
    'in progress, silent for 14 minutes' => [ProcessStatus::IN_PROGRESS, 14],
]);

dataset('stale start activities', [
    'queued for 11 minutes' => [ProcessStatus::QUEUED, 11],
    'queued for a day' => [ProcessStatus::QUEUED, 60 * 24],
    'in progress, silent for 16 minutes' => [ProcessStatus::IN_PROGRESS, 16],
]);

it('blocks database start and restart while a live start activity exists', function (ProcessStatus $status, int $minutes) {
    $activity = startActivity($this->database->uuid, $status, 'database-start', $minutes);
    $heading = databaseHeadingFor($this->database);

    expect($heading->checkDeployments())->toBeTrue()
        ->and($heading->runningActivityId)->toBe($activity->id);
})->with('blocking start activities');

it('does not let a stale start activity block database start and restart', function (ProcessStatus $status, int $minutes) {
    startActivity($this->database->uuid, $status, 'database-start', $minutes);
    $heading = databaseHeadingFor($this->database);

    expect($heading->checkDeployments())->toBeFalse()
        ->and($heading->runningActivityId)->toBeNull();
})->with('stale start activities');

it('does not block database start once the latest activity finished', function () {
    startActivity($this->database->uuid, ProcessStatus::FINISHED, 'database-start');

    expect(databaseHeadingFor($this->database)->checkDeployments())->toBeFalse();
});

it('reports a service as starting only while its start activity is live', function (ProcessStatus $status, int $minutes) {
    startActivity($this->service->uuid, $status, minutesSinceLastUpdate: $minutes);

    expect($this->service->isStarting())->toBeTrue()
        ->and(serviceHeadingFor($this->service)->checkDeployments())->toBeTrue();
})->with('blocking start activities');

it('does not report a service as starting because of a stale start activity', function (ProcessStatus $status, int $minutes) {
    startActivity($this->service->uuid, $status, minutesSinceLastUpdate: $minutes);

    expect($this->service->isStarting())->toBeFalse()
        ->and(serviceHeadingFor($this->service)->checkDeployments())->toBeFalse();
})->with('stale start activities');

it('marks interrupted database and service start activities as failed on startup', function () {
    $queuedDatabaseStart = startActivity($this->database->uuid, ProcessStatus::QUEUED, 'database-start');
    $runningDatabaseStart = startActivity((string) Str::uuid(), ProcessStatus::IN_PROGRESS, 'database-start');
    $runningServiceStart = startActivity($this->service->uuid, ProcessStatus::IN_PROGRESS);
    $finishedDatabaseStart = startActivity($this->database->uuid, ProcessStatus::FINISHED, 'database-start');
    $failedDatabaseStart = startActivity($this->database->uuid, ProcessStatus::ERROR, 'database-start');
    $unrelatedRunningProcess = startActivity('not-a-start-resource', ProcessStatus::IN_PROGRESS);

    expect(ResourceStartActivity::failInterrupted())->toBe(3);

    foreach ([$queuedDatabaseStart, $runningDatabaseStart, $runningServiceStart] as $activity) {
        $activity->refresh();
        expect(data_get($activity, 'properties.status'))->toBe(ProcessStatus::ERROR->value)
            ->and(data_get($activity, 'properties.error'))->toBe('Interrupted by a Coolify restart.')
            ->and(data_get($activity, 'properties.exitCode'))->toBe(1);
    }

    expect(data_get($finishedDatabaseStart->refresh(), 'properties.status'))->toBe(ProcessStatus::FINISHED->value)
        ->and(data_get($finishedDatabaseStart, 'properties.error'))->toBeNull()
        ->and(data_get($failedDatabaseStart->refresh(), 'properties.status'))->toBe(ProcessStatus::ERROR->value)
        ->and(data_get($failedDatabaseStart, 'properties.error'))->toBeNull()
        ->and(data_get($unrelatedRunningProcess->refresh(), 'properties.status'))->toBe(ProcessStatus::IN_PROGRESS->value);
});

it('runs the interrupted start cleanup from app:init', function () {
    $source = file_get_contents(app_path('Console/Commands/Init.php'));

    expect($source)->toContain('ResourceStartActivity::failInterrupted()');
});
