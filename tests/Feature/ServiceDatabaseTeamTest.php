<?php

use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('returns the correct team through the service relationship chain', function () {
    $team = Team::factory()->create();

    $project = Project::forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Project',
        'team_id' => $team->id,
    ]);

    $environment = Environment::forceCreate([
        'name' => 'test-env-'.Str::random(8),
        'project_id' => $project->id,
    ]);

    $service = Service::forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'supabase',
        'environment_id' => $environment->id,
        'destination_id' => 1,
        'destination_type' => 'App\Models\StandaloneDocker',
        'docker_compose_raw' => 'version: "3"',
    ]);

    $serviceDatabase = ServiceDatabase::forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'supabase-db',
        'service_id' => $service->id,
    ]);

    expect($serviceDatabase->team())->not->toBeNull()
        ->and($serviceDatabase->team()->id)->toBe($team->id);
});

it('returns the correct team for ServiceApplication through the service relationship chain', function () {
    $team = Team::factory()->create();

    $project = Project::forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Project',
        'team_id' => $team->id,
    ]);

    $environment = Environment::forceCreate([
        'name' => 'test-env-'.Str::random(8),
        'project_id' => $project->id,
    ]);

    $service = Service::forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'supabase',
        'environment_id' => $environment->id,
        'destination_id' => 1,
        'destination_type' => 'App\Models\StandaloneDocker',
        'docker_compose_raw' => 'version: "3"',
    ]);

    $serviceApplication = ServiceApplication::forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'supabase-studio',
        'service_id' => $service->id,
    ]);

    expect($serviceApplication->team())->not->toBeNull()
        ->and($serviceApplication->team()->id)->toBe($team->id);
});
