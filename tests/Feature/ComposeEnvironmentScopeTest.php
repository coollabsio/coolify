<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $this->destination = StandaloneDocker::where('server_id', $server->id)->first();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
});

it('gives each Compose service the unscoped variables and the variables scoped to it', function () {
    $lines = collect(['SHARED=1', 'DB_PASSWORD="secret"', 'API_KEY=key', "MULTI=\"a\nb\""]);
    $scopes = ['DB_PASSWORD' => ['db'], 'API_KEY' => ['api', 'worker']];

    expect(composeEnvironmentLinesForService($lines, $scopes, 'db')->all())->toBe(['SHARED=1', 'DB_PASSWORD="secret"', "MULTI=\"a\nb\""])
        ->and(composeEnvironmentLinesForService($lines, $scopes, 'worker')->all())->toBe(['SHARED=1', 'API_KEY=key', "MULTI=\"a\nb\""])
        ->and(composeEnvironmentLinesForService($lines, $scopes, 'removed')->all())->toBe(['SHARED=1', "MULTI=\"a\nb\""]);
});

it('names the env file of a Compose service without leaving the work directory', function () {
    expect(composeServiceEnvironmentFile('web-pr-3'))->toBe('.coolify.web-pr-3.env')
        ->and(composeServiceEnvironmentFile('../etc/x'))->toBe('.coolify..._etc_x.env');
});

it('saves an empty scope as all services', function () {
    $service = Service::factory()->create(['environment_id' => $this->environment->id, 'destination_id' => $this->destination->id, 'destination_type' => StandaloneDocker::class]);
    $scoped = $service->environment_variables()->create(['key' => 'A', 'value' => '1', 'compose_services' => ['db', 'db', '']]);
    $empty = $service->environment_variables()->create(['key' => 'B', 'value' => '2', 'compose_services' => []]);

    expect($scoped->fresh()->compose_services)->toBe(['db'])
        ->and($empty->fresh()->compose_services)->toBeNull()
        ->and(composeEnvironmentVariableScopes($service->environment_variables()->get()))->toBe(['A' => ['db']]);
});

it('copies the scope to the preview variable of an application', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockercompose',
    ]);
    EnvironmentVariable::create([
        'key' => 'API_KEY', 'value' => 'x', 'compose_services' => ['api'],
        'resourceable_type' => Application::class, 'resourceable_id' => $application->id,
    ]);

    expect($application->environment_variables_preview()->where('key', 'API_KEY')->first()->compose_services)->toBe(['api']);
});

it('lists the Compose services that a variable can be limited to', function () {
    $compose = "services:\n  web:\n    image: nginx\n  db:\n    image: postgres\n";
    $composeApplication = Application::factory()->create([
        'environment_id' => $this->environment->id, 'destination_id' => $this->destination->id, 'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockercompose', 'docker_compose_raw' => $compose,
    ]);
    $dockerfileApplication = Application::factory()->create([
        'environment_id' => $this->environment->id, 'destination_id' => $this->destination->id, 'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockerfile', 'docker_compose_raw' => $compose,
    ]);

    expect(composeServiceNamesForEnvironmentScope($composeApplication))->toBe(['web', 'db'])
        ->and(composeServiceNamesForEnvironmentScope($dockerfileApplication))->toBe([]);
});
