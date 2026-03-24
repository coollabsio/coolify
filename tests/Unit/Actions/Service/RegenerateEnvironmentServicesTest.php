<?php

use App\Actions\Service\RegenerateEnvironmentServices;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('restores soft-deleted services and parses stack services', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);

    $project = Project::query()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'regenerate-project',
        'team_id' => $team->id,
    ]);

    $environment = Environment::query()->create([
        'name' => 'production',
        'project_id' => $project->id,
    ]);

    $service = Service::query()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'laravel-stack',
        'environment_id' => $environment->id,
        'server_id' => 1,
        'destination_type' => 'App\\Models\\StandaloneDocker',
        'destination_id' => 1,
        'docker_compose_raw' => <<<YAML
services:
  nginx:
    image: nginx:alpine
YAML,
    ]);

    $service->delete();

    $result = app(RegenerateEnvironmentServices::class)->handle($environment);

    expect($result['services'])->toBe(1);
    expect($result['restored'])->toBe(1);
    expect($result['parsed'])->toBe(1);
    expect($result['failed'])->toBe(0);
    expect(Service::query()->find($service->id))->not->toBeNull();
    expect($service->fresh()->applications()->count())->toBeGreaterThanOrEqual(1);
});
