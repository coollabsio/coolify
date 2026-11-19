<?php

use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'static_image' => 'nginx:alpine',
    ]);
});

function staticImageForDeployment(Application $application): string
{
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($job, 'application'))->setValue($job, $application);

    return (new ReflectionMethod($job, 'staticImage'))->invoke($job);
}

test('a supported static image is used for the deployment', function () {
    expect(staticImageForDeployment($this->application))->toBe('nginx:alpine');
});

test('an unsupported stored static image stops the deployment with a clear error', function () {
    // Older versions accepted any static image through the API update endpoint.
    $this->application->forceFill(['static_image' => 'nginx:1.27-alpine'])->saveQuietly();

    expect(fn () => staticImageForDeployment($this->application->fresh()))
        ->toThrow(DeploymentException::class, "The static image 'nginx:1.27-alpine' is not supported.");
});
