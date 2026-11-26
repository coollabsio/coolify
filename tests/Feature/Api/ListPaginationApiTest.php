<?php

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\DockerCleanupExecution;
use App\Models\InstanceSettings;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->bearerToken = $this->user->createToken('test-token', ['read'])->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $environment = Project::factory()->create(['team_id' => $this->team->id])->environments()->first();
    $placement = ['environment_id' => $environment->id, 'destination_id' => $destination->id, 'destination_type' => $destination->getMorphClass()];
    $this->application = Application::factory()->create($placement);
    $this->database = StandalonePostgresql::create(['name' => 'db', 'postgres_password' => 'secret'] + $placement);
});

function getList(string $path, array $query = []): TestResponse
{
    return test()->withHeaders(['Authorization' => 'Bearer '.test()->bearerToken])
        ->getJson('/api/v1/'.$path.($query ? '?'.http_build_query($query) : ''));
}

test('application previews are a plain array that paginates with a total header', function () {
    foreach ([1, 2, 3] as $pullRequestId) {
        ApplicationPreview::create(['application_id' => $this->application->id, 'pull_request_id' => $pullRequestId, 'pull_request_html_url' => '']);
    }
    $path = "applications/{$this->application->uuid}/previews";

    $all = getList($path)->assertOk()->assertJsonCount(3);
    expect($all->headers->has('X-Total-Count'))->toBeFalse();

    getList($path, ['page' => 2, 'per_page' => 2])
        ->assertOk()
        ->assertHeader('X-Total-Count', '3')
        ->assertJsonCount(1)
        ->assertJsonPath('0.pull_request_id', 1);
});

test('backup executions are a plain array that paginates newest first', function () {
    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'database_type' => $this->database->getMorphClass(),
        'database_id' => $this->database->id,
        'team_id' => $this->team->id,
    ]);
    foreach (['first.gz', 'second.gz', 'third.gz'] as $index => $filename) {
        ScheduledDatabaseBackupExecution::query()->create(['scheduled_database_backup_id' => $backup->id, 'status' => 'success', 'filename' => $filename])
            ->forceFill(['created_at' => now()->addMinutes($index)])->save();
    }
    $path = "databases/{$this->database->uuid}/backups/{$backup->uuid}/executions";

    expect(collect(getList($path)->assertOk()->json())->pluck('filename')->all())->toBe(['third.gz', 'second.gz', 'first.gz']);
    getList($path, ['per_page' => 1, 'page' => 3])
        ->assertOk()
        ->assertHeader('X-Total-Count', '3')
        ->assertJsonPath('0.filename', 'first.gz');
});

test('scheduled task executions paginate with a total header', function () {
    $task = ScheduledTask::create([
        'name' => 'task',
        'command' => 'echo hi',
        'frequency' => '* * * * *',
        'application_id' => $this->application->id,
        'team_id' => $this->team->id,
    ]);
    foreach (range(1, 3) as $number) {
        ScheduledTaskExecution::create(['scheduled_task_id' => $task->id, 'status' => 'success']);
    }

    getList("applications/{$this->application->uuid}/scheduled-tasks/{$task->uuid}/executions", ['per_page' => 2])
        ->assertOk()
        ->assertHeader('X-Total-Count', '3')
        ->assertJsonCount(2);
});

test('docker cleanup executions return the latest 20 by default and reach older pages', function () {
    foreach (range(1, 25) as $number) {
        DockerCleanupExecution::create(['server_id' => $this->server->id, 'status' => 'success', 'message' => "run {$number}"]);
    }
    $path = "servers/{$this->server->uuid}/docker-cleanup/executions";

    getList($path)->assertOk()->assertHeader('X-Total-Count', '25')->assertJsonCount(20)->assertJsonPath('0.message', 'run 25');
    getList($path, ['page' => 2])->assertOk()->assertJsonCount(5)->assertJsonPath('4.message', 'run 1');
});

test('vulnerabilities list is a plain array', function () {
    $response = getList('vulnerabilities')->assertOk();

    expect($response->json())->toBeArray()->not->toHaveKey('resources');
    getList('vulnerabilities', ['per_page' => 1])->assertOk()->assertHeader('X-Total-Count');
});

test('paginated list endpoints reject invalid pagination values', function (string $path) {
    $path = str_replace(['{application}', '{server}'], [$this->application->uuid, $this->server->uuid], $path);

    getList($path, ['per_page' => 101])->assertStatus(422)->assertJsonValidationErrors(['per_page']);
    getList($path, ['page' => 0])->assertStatus(422)->assertJsonValidationErrors(['page']);
})->with([
    'previews' => 'applications/{application}/previews',
    'docker cleanup executions' => 'servers/{server}/docker-cleanup/executions',
    'vulnerabilities' => 'vulnerabilities',
]);
