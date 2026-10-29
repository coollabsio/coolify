<?php

use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Livewire\Project\Application\General;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], []));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $project->environments()->first() ?? Environment::factory()->create(['project_id' => $project->id]);
});

function makeRegistryImageApplication(Environment $environment, StandaloneDocker $destination, array $attributes = []): Application
{
    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'nixpacks',
        'docker_registry_image_name' => 'ghcr.io/acme/monorepo',
        ...$attributes,
    ]);
}

function runSharedRegistryImageGuard(Application $application): void
{
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $job = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('application')->setValue($job, $application);
    $reflection->getMethod('ensureRegistryImageIsNotShared')->invoke($job);
}

describe('deployment guard', function () {
    test('fails when another building application of the team pushes the same image name', function () {
        makeRegistryImageApplication($this->environment, $this->destination, ['name' => 'web']);
        $server = makeRegistryImageApplication($this->environment, $this->destination, ['name' => 'server', 'build_pack' => 'dockerfile']);

        expect(fn () => runSharedRegistryImageGuard($server))
            ->toThrow(DeploymentException::class, 'The application web also builds the Docker image ghcr.io/acme/monorepo');
    });

    test('allows unique image names', function () {
        makeRegistryImageApplication($this->environment, $this->destination, ['docker_registry_image_name' => 'ghcr.io/acme/web']);
        $server = makeRegistryImageApplication($this->environment, $this->destination, ['docker_registry_image_name' => 'ghcr.io/acme/server']);

        runSharedRegistryImageGuard($server);
    })->throwsNoExceptions();

    test('allows applications that do not push a built image', function (array $other, array $deployed) {
        makeRegistryImageApplication($this->environment, $this->destination, $other);
        $application = makeRegistryImageApplication($this->environment, $this->destination, $deployed);

        runSharedRegistryImageGuard($application);
    })->with([
        'other runs the image' => [['build_pack' => 'dockerimage'], []],
        'other is docker compose' => [['build_pack' => 'dockercompose'], []],
        'deployed runs the image' => [[], ['build_pack' => 'dockerimage']],
        'no image name' => [['docker_registry_image_name' => null], ['docker_registry_image_name' => null]],
    ])->throwsNoExceptions();

    test('ignores applications of other teams', function () {
        $otherTeam = Team::factory()->create();
        $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
        $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->firstOrFail();
        $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
        $otherEnvironment = $otherProject->environments()->first() ?? Environment::factory()->create(['project_id' => $otherProject->id]);
        makeRegistryImageApplication($otherEnvironment, $otherDestination);
        $application = makeRegistryImageApplication($this->environment, $this->destination);

        runSharedRegistryImageGuard($application);
    })->throwsNoExceptions();
});

test('the general form rejects an image name that another building application uses', function () {
    makeRegistryImageApplication($this->environment, $this->destination, ['name' => 'web']);
    $server = makeRegistryImageApplication($this->environment, $this->destination, [
        'docker_registry_image_name' => 'ghcr.io/acme/server',
        'ports_exposes' => '3000',
        'base_directory' => '/',
        'redirect' => 'both',
        'static_image' => 'nginx:alpine',
        'is_http_basic_auth_enabled' => false,
    ]);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    Livewire::test(General::class, ['application' => $server])
        ->set('dockerRegistryImageName', 'ghcr.io/acme/monorepo')
        ->call('submit')
        ->assertHasErrors('dockerRegistryImageName');

    expect($server->fresh()->docker_registry_image_name)->toBe('ghcr.io/acme/server');
});

describe('API', function () {
    beforeEach(function () {
        InstanceSettings::query()->whereKey(0)->update(['is_api_enabled' => true]);
        $plainTextToken = Str::random(40);
        $token = $this->user->tokens()->create([
            'name' => 'shared-image-name-test',
            'token' => hash('sha256', $plainTextToken),
            'abilities' => ['*'],
            'team_id' => $this->team->id,
        ]);
        $this->apiHeaders = ['Authorization' => 'Bearer '.$token->getKey().'|'.$plainTextToken];
        $this->server = Server::query()->whereKey($this->destination->server_id)->firstOrFail();
        $this->project = Project::query()->whereKey($this->environment->project_id)->firstOrFail();
        makeRegistryImageApplication($this->environment, $this->destination, ['name' => 'web']);
    });

    test('update rejects an image name that another building application uses', function () {
        $application = makeRegistryImageApplication($this->environment, $this->destination, ['docker_registry_image_name' => 'ghcr.io/acme/server']);

        $this->withHeaders($this->apiHeaders)
            ->patchJson("/api/v1/applications/{$application->uuid}", ['docker_registry_image_name' => 'ghcr.io/acme/monorepo'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.docker_registry_image_name.0', fn (string $message) => str_contains($message, 'The application web also builds'));

        expect($application->fresh()->docker_registry_image_name)->toBe('ghcr.io/acme/server');
    });

    test('update accepts other changes of an application that already shares its image name', function () {
        $application = makeRegistryImageApplication($this->environment, $this->destination);

        $this->withHeaders($this->apiHeaders)
            ->patchJson("/api/v1/applications/{$application->uuid}", ['description' => 'api'])
            ->assertOk();
    });

    test('create rejects an image name that another building application uses', function () {
        $count = Application::count();

        $this->withHeaders($this->apiHeaders)
            ->postJson('/api/v1/applications/dockerfile', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'server_uuid' => $this->server->uuid,
                'dockerfile' => base64_encode("FROM nginx:alpine\nEXPOSE 80"),
                'docker_registry_image_name' => 'ghcr.io/acme/monorepo',
                'autogenerate_domain' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('docker_registry_image_name');

        expect(Application::count())->toBe($count);
    });

    test('create accepts a unique image name', function () {
        $this->withHeaders($this->apiHeaders)
            ->postJson('/api/v1/applications/dockerfile', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'server_uuid' => $this->server->uuid,
                'dockerfile' => base64_encode("FROM nginx:alpine\nEXPOSE 80"),
                'docker_registry_image_name' => 'ghcr.io/acme/server',
                'autogenerate_domain' => false,
            ])
            ->assertCreated();
    });
});
