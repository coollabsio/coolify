<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = $this->server->standaloneDockers()->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('cloning a nixpacks application does not duplicate NIXPACKS_NODE_VERSION', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'build_pack' => 'nixpacks',
        'redirect' => 'both',
    ]);
    $application->settings->fill(['is_container_label_readonly_enabled' => false])->save();

    $application->environment_variables()->where('key', 'NIXPACKS_NODE_VERSION')->firstOrFail()->update(['value' => '20']);

    $newApp = clone_application($application, $this->destination, [
        'environment_id' => $this->environment->id,
    ]);

    $production = $newApp->environment_variables()->where('key', 'NIXPACKS_NODE_VERSION')->get();
    $preview = $newApp->environment_variables_preview()->where('key', 'NIXPACKS_NODE_VERSION')->get();

    expect($production)->toHaveCount(1)
        ->and($production->first()->value)->toBe('20')
        ->and($preview)->toHaveCount(1);
});

test('the migration keeps the most recently changed NIXPACKS_NODE_VERSION of each application and preview flag', function () {
    $attributes = [
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'build_pack' => 'nixpacks',
    ];
    $application = Application::factory()->create($attributes);
    $other = Application::factory()->create($attributes);

    // The rows an earlier clone_application() added next to the defaults of the created hooks.
    $copies = collect([false, true])->map(fn (bool $isPreview) => EnvironmentVariable::query()->create([
        'key' => 'NIXPACKS_NODE_VERSION',
        'value' => '20',
        'is_preview' => $isPreview,
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]));
    EnvironmentVariable::query()->whereIn('id', $copies->pluck('id'))->update(['updated_at' => now()->addMinute()]);

    $migration = require database_path('migrations/2026_10_09_124641_remove_duplicate_nixpacks_node_version_environment_variables.php');
    $migration->up();

    $rows = EnvironmentVariable::query()
        ->where('resourceable_type', Application::class)
        ->where('key', 'NIXPACKS_NODE_VERSION');

    expect((clone $rows)->where('resourceable_id', $application->id)->pluck('id')->sort()->values()->all())->toBe($copies->pluck('id')->sort()->values()->all())
        ->and((clone $rows)->where('resourceable_id', $other->id)->count())->toBe(2);
});
