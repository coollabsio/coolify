<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('clones an application with fresh persistent volume uuids and copied environment variables', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    $server = Server::factory()->create(['team_id' => $team->id]);
    $sourceDestination = StandaloneDocker::factory()->create(['server_id' => $server->id]);
    $targetDestination = StandaloneDocker::factory()->create(['server_id' => $server->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $sourceApplication = Application::factory()->create([
        'build_pack' => 'dockerfile',
        'environment_id' => $environment->id,
        'destination_id' => $sourceDestination->id,
        'destination_type' => $sourceDestination->getMorphClass(),
    ]);

    $sourceVolume = $sourceApplication->persistentStorages()->create([
        'name' => $sourceApplication->uuid.'-app-data',
        'mount_path' => '/var/lib/app',
        'is_preview_suffix_enabled' => true,
    ]);

    $sourceApplication->environment_variables()->create([
        'key' => 'APP_SECRET',
        'value' => 'super-secret',
        'is_preview' => false,
        'is_multiline' => false,
        'is_literal' => false,
        'is_runtime' => true,
        'is_buildtime' => true,
    ]);

    $clonedApplication = clone_application($sourceApplication, $targetDestination, [
        'name' => 'cloned-application',
    ]);

    $clonedApplication->load(['persistentStorages', 'environment_variables']);
    $clonedVolume = $clonedApplication->persistentStorages->first();

    expect($clonedApplication->persistentStorages)->toHaveCount(1)
        ->and($clonedVolume->uuid)->not->toBe($sourceVolume->uuid)
        ->and($clonedVolume->resource_id)->toBe($clonedApplication->id)
        ->and($clonedApplication->environment_variables->pluck('key')->all())->toContain('APP_SECRET');
});
