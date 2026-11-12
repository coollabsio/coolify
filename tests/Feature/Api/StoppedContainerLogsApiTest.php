<?php

use App\Models\Application;
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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    $this->token = $user->createToken('stopped-container-logs', ['read', 'read:sensitive'], teamId: $team->id)->plainTextToken;

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
    $destinationAttributes = ['destination_id' => $destination->id, 'destination_type' => $destination->getMorphClass()];

    $this->application = Application::factory()->create(['environment_id' => $environment->id, ...$destinationAttributes]);
    $this->database = StandalonePostgresql::query()->create([
        'name' => 'stopped-database',
        'environment_id' => $environment->id,
        'postgres_password' => 'secret',
        ...$destinationAttributes,
    ]);
    $this->service = Service::factory()->create(['environment_id' => $environment->id, 'server_id' => $server->id, ...$destinationAttributes]);
    $this->serviceApplication = ServiceApplication::create(['name' => 'web', 'service_id' => $this->service->id]);
    $this->serviceDatabase = ServiceDatabase::create(['name' => 'db', 'service_id' => $this->service->id]);
});

/**
 * @param  list<array<string, string>>  $containers  `docker ps -a` rows
 */
function fakeDockerWithContainers(array $containers): void
{
    Process::fake(function ($process) use ($containers) {
        $script = remoteProcessScript($process);

        return match (true) {
            str_contains($script, 'docker ps -a') => Process::result(output: collect($containers)->map(fn ($row) => json_encode($row))->implode(PHP_EOL)),
            str_contains($script, 'docker inspect') => $containers === []
                ? Process::result(errorOutput: 'Error: No such object', exitCode: 1)
                : Process::result(output: json_encode(['State' => ['Status' => $containers[0]['State']]])),
            str_contains($script, 'docker logs') => Process::result(output: str_contains($script, 'running-id') ? 'running container output' : 'stopped container output'),
            default => Process::result(),
        };
    });
}

dataset('log endpoints', [
    'application' => [
        fn () => "/api/v1/applications/{$this->application->uuid}/logs",
        fn () => ['Labels' => "coolify.applicationId={$this->application->id}"],
        'Application is not running.',
    ],
    'database' => [
        fn () => "/api/v1/databases/{$this->database->uuid}/logs",
        fn () => ['Labels' => "coolify.databaseUuid={$this->database->uuid}"],
        'Database is not running.',
    ],
    'service sub-container' => [
        fn () => "/api/v1/services/{$this->service->uuid}/logs?sub_service_name=web",
        fn () => ['Labels' => "coolify.name=web-{$this->service->uuid}"],
        'Container not found.',
    ],
    'service application' => [
        fn () => "/api/v1/services/{$this->service->uuid}/applications/{$this->serviceApplication->uuid}/logs",
        fn () => [],
        'Service application container is not running.',
    ],
    'service database' => [
        fn () => "/api/v1/services/{$this->service->uuid}/databases/{$this->serviceDatabase->uuid}/logs",
        fn () => [],
        'Service database container is not running.',
    ],
]);

it('returns the logs of a stopped container', function (string $url, array $container) {
    fakeDockerWithContainers([['ID' => 'stopped-id', 'Names' => 'stopped-name', 'State' => 'exited', ...$container]]);

    $this->withToken($this->token)->getJson($url)
        ->assertOk()
        ->assertExactJson(['logs' => 'stopped container output']);
})->with('log endpoints');

it('keeps the error when no container exists', function (string $url, array $container, string $message) {
    fakeDockerWithContainers([]);

    $this->withToken($this->token)->getJson($url)
        ->assertJsonPath('message', $message);

    Process::assertDidntRun(fn ($process) => str_contains(remoteProcessScript($process), 'docker logs'));
})->with('log endpoints');

it('prefers the running application container over a stopped one', function () {
    $labels = "coolify.applicationId={$this->application->id}";
    fakeDockerWithContainers([
        ['ID' => 'stopped-id', 'Names' => 'stopped-name', 'State' => 'exited', 'Labels' => $labels],
        ['ID' => 'running-id', 'Names' => 'running-name', 'State' => 'running', 'Labels' => $labels],
    ]);

    $this->withToken($this->token)->getJson("/api/v1/applications/{$this->application->uuid}/logs")
        ->assertOk()
        ->assertExactJson(['logs' => 'running container output']);
});
