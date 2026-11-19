<?php

use App\Livewire\Project\Application\General;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
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
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    InstanceSettings::unguarded(function () {
        InstanceSettings::updateOrCreate(['id' => 0], []);
    });

    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id, 'network' => 'coolify-test']);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockerimage',
        'ports_exposes' => '80',
    ]);

    EnvironmentVariable::create([
        'key' => 'PORT',
        'value' => '3000',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
        'is_preview' => false,
    ]);
});

test('shows a PORT mismatch warning when readonly labels are enabled', function () {
    Livewire::test(General::class, ['application' => $this->application->fresh()])
        ->assertSee('PORT mismatch detected');
});

/**
 * Regression for #10941: with readonly labels disabled, the Ports Exposes field is
 * read-only and custom labels control proxy routing, so the warning cannot be acted on.
 */
test('does not show a PORT mismatch warning when readonly labels are disabled', function () {
    $this->application->settings()->update(['is_container_label_readonly_enabled' => false]);

    Livewire::test(General::class, ['application' => $this->application->fresh()])
        ->assertDontSee('PORT mismatch detected');
});
