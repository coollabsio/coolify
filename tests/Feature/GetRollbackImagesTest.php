<?php

use App\Actions\Application\GetRollbackImages;
use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $team = Team::factory()->create();
    // The server is not reachable, so only registry images are listed and no SSH command runs.
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::factory()->create(['server_id' => $server->id, 'network' => 'rollback-test']);
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'nixpacks',
        'docker_registry_image_name' => 'ghcr.io/coollabs/app',
    ]);
});

function rollbackDeployment(Application $application, string $commit, string $finishedAt, array $attributes = []): void
{
    ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => fake()->uuid(),
        'commit' => $commit,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'pull_request_id' => 0,
        ...$attributes,
    ])->forceFill(['updated_at' => $finishedAt])->saveQuietly();
}

it('lists the commits of successful deployments as registry images, newest first and once per commit', function () {
    rollbackDeployment($this->application, 'aaaaaaa', '2026-10-01 10:00:00');
    rollbackDeployment($this->application, 'bbbbbbb', '2026-10-02 10:00:00');
    rollbackDeployment($this->application, 'aaaaaaa', '2026-10-03 10:00:00');
    rollbackDeployment($this->application, 'ccccccc', '2026-10-04 10:00:00', ['status' => ApplicationDeploymentStatus::FAILED->value]);
    rollbackDeployment($this->application, 'ddddddd', '2026-10-04 10:00:00', ['pull_request_id' => 5]);
    rollbackDeployment($this->application, 'HEAD', '2026-10-04 10:00:00');

    $result = GetRollbackImages::run($this->application);

    expect($result['current'])->toBeNull()
        ->and($result['images'])->toBe([
            ['tag' => 'aaaaaaa', 'created_at' => '2026-10-03 10:00:00', 'is_current' => false, 'source' => 'registry'],
            ['tag' => 'bbbbbbb', 'created_at' => '2026-10-02 10:00:00', 'is_current' => false, 'source' => 'registry'],
        ]);
});

it('limits the registry images to the most recent deployments', function () {
    foreach (range(1, GetRollbackImages::REGISTRY_DEPLOYMENTS_LIMIT + 2) as $day) {
        rollbackDeployment($this->application, sprintf('%07d', $day), sprintf('2026-10-%02d 10:00:00', $day));
    }

    $images = GetRollbackImages::run($this->application)['images'];

    expect($images)->toHaveCount(GetRollbackImages::REGISTRY_DEPLOYMENTS_LIMIT)
        ->and($images[0]['tag'])->toBe(sprintf('%07d', GetRollbackImages::REGISTRY_DEPLOYMENTS_LIMIT + 2));
});

it('lists no registry images when the application does not push commit images', function (array $attributes) {
    $this->application->update($attributes);
    rollbackDeployment($this->application, 'aaaaaaa', '2026-10-01 10:00:00');

    expect(GetRollbackImages::run($this->application->fresh())['images'])->toBe([]);
})->with([
    'no registry image name' => [['docker_registry_image_name' => null]],
    'docker image build pack' => [['build_pack' => 'dockerimage']],
    'docker compose build pack' => [['build_pack' => 'dockercompose']],
    'dockerfile without git' => [['build_pack' => 'dockerfile', 'dockerfile' => 'FROM nginx']],
]);
