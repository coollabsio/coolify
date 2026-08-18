<?php

use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates application settings for internally created applications', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->create([
        'team_id' => $team->id,
    ]);
    $environment = Environment::factory()->create([
        'project_id' => $project->id,
    ]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
    ]);
    $destination = $server->standaloneDockers()->firstOrFail();

    $application = Application::create([
        'name' => 'internal-app',
        'git_repository' => 'https://github.com/coollabsio/coolify',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $setting = ApplicationSetting::query()
        ->where('application_id', $application->id)
        ->first();

    expect($application->environment_id)->toBe($environment->id);
    expect($setting)->not->toBeNull();
    expect($setting?->application_id)->toBe($application->id);
});

it('creates services with protected relationship ids in trusted internal paths', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->create([
        'team_id' => $team->id,
    ]);
    $environment = Environment::factory()->create([
        'project_id' => $project->id,
    ]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
    ]);
    $destination = $server->standaloneDockers()->firstOrFail();

    $service = Service::create([
        'docker_compose_raw' => 'services: {}',
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'service_type' => 'test-service',
    ]);

    expect($service->environment_id)->toBe($environment->id);
    expect($service->server_id)->toBe($server->id);
    expect($service->destination_id)->toBe($destination->id);
    expect($service->destination_type)->toBe($destination->getMorphClass());
});
