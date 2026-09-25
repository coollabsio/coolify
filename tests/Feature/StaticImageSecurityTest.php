<?php

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
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->token = $this->user->createToken('static-image-security-test', ['*'])->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function staticImageApplication(): Application
{
    return Application::factory()->create([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'static_image' => 'nginx:alpine',
        'base_directory' => '/',
        'is_http_basic_auth_enabled' => false,
        'redirect' => 'no',
    ]);
}

function staticImageApiHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

function staticImageCreatePayload(string $image): array
{
    return [
        'project_uuid' => test()->project->uuid,
        'environment_uuid' => test()->environment->uuid,
        'server_uuid' => test()->server->uuid,
        'git_repository' => 'https://gitlab.com/coolify/test-static-app',
        'git_branch' => 'main',
        'build_pack' => 'static',
        'ports_exposes' => '80',
        'autogenerate_domain' => false,
        'static_image' => $image,
    ];
}

test('API create rejects invalid static images', function (string $image) {
    $this->withHeaders(staticImageApiHeaders())
        ->postJson('/api/v1/applications/public', staticImageCreatePayload($image))
        ->assertUnprocessable()
        ->assertInvalid(['static_image']);
})->with(['shell' => 'nginx:alpine;id>/tmp/pwn', 'dockerfile newline' => "nginx:alpine\nRUN id"]);

test('API update rejects invalid static images without saving them', function (string $image) {
    $application = staticImageApplication();

    $this->withHeaders(staticImageApiHeaders())
        ->patchJson("/api/v1/applications/{$application->uuid}", ['static_image' => $image])
        ->assertUnprocessable()
        ->assertInvalid(['static_image']);

    expect($application->refresh()->static_image)->toBe('nginx:alpine');
})->with(['shell' => 'nginx:alpine;id>/tmp/pwn', 'dockerfile newline' => "nginx:alpine\nRUN id"]);

test('API accepts the allowed static image on create and update', function () {
    $response = $this->withHeaders(staticImageApiHeaders())
        ->postJson('/api/v1/applications/public', staticImageCreatePayload('nginx:alpine'))
        ->assertCreated();

    $application = Application::query()->where('uuid', $response->json('uuid'))->firstOrFail();
    expect($application->static_image)->toBe('nginx:alpine');

    $this->withHeaders(staticImageApiHeaders())
        ->patchJson("/api/v1/applications/{$application->uuid}", ['static_image' => 'nginx:alpine'])
        ->assertOk();
});

test('Livewire rejects invalid static images without saving them', function (string $image) {
    $application = staticImageApplication();
    $this->actingAs($this->user);

    Livewire::test(General::class, ['application' => $application])
        ->set('staticImage', $image)
        ->call('submit')
        ->assertDispatched('error', fn (string $event, array $params): bool => str_contains(strtolower($params[0]), 'static image') && str_contains(strtolower($params[0]), 'invalid'))
        ->assertNotDispatched('success');

    expect($application->refresh()->static_image)->toBe('nginx:alpine');
})->with(['shell' => 'nginx:alpine;id>/tmp/pwn', 'dockerfile newline' => "nginx:alpine\nRUN id"]);

test('Livewire accepts the allowed static image', function () {
    $application = staticImageApplication();
    $this->actingAs($this->user);

    Livewire::test(General::class, ['application' => $application])
        ->set('staticImage', 'nginx:alpine')
        ->call('submit')
        ->assertNotDispatched('error');

    expect($application->refresh()->static_image)->toBe('nginx:alpine');
});

test('deployment rejects invalid legacy static images before they reach shell or Dockerfile', function (string $image) {
    $application = staticImageApplication();
    $application->static_image = $image;

    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    $property = new ReflectionProperty(ApplicationDeploymentJob::class, 'application');
    $property->setValue($job, $application);

    expect(fn () => (new ReflectionMethod(ApplicationDeploymentJob::class, 'staticImage'))->invoke($job))
        ->toThrow(ValueError::class);
})->with(['shell' => 'nginx:alpine;id>/tmp/pwn', 'dockerfile newline' => "nginx:alpine\nRUN id"]);

test('deployment accepts the allowed static image', function () {
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(ApplicationDeploymentJob::class, 'application'))->setValue($job, staticImageApplication());

    expect((new ReflectionMethod(ApplicationDeploymentJob::class, 'staticImage'))->invoke($job))
        ->toBe('nginx:alpine');
});
