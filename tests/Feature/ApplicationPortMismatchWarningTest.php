<?php

use App\Livewire\Project\Application\General;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], []));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id]);
    $this->destination = StandaloneDocker::factory()->create(['server_id' => $server->id, 'network' => 'coolify-test']);
});

function applicationWithPortMismatch(bool $readonlyLabels): Application
{
    $application = Application::factory()->create([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockerimage',
        'base_directory' => '/',
        'redirect' => 'no',
        'ports_exposes' => '80',
    ]);

    EnvironmentVariable::create([
        'key' => 'PORT',
        'value' => '3000',
        'is_preview' => false,
        'resourceable_id' => $application->id,
        'resourceable_type' => $application->getMorphClass(),
    ]);

    $application->settings->update(['is_container_label_readonly_enabled' => $readonlyLabels]);

    return $application->refresh();
}

it('points at the labels section when the ports field cannot be edited', function () {
    $application = applicationWithPortMismatch(readonlyLabels: false);

    Livewire::test(General::class, ['application' => $application])
        ->assertSuccessful()
        ->assertSee('PORT mismatch detected')
        ->assertSee('set the port in the labels section instead')
        ->assertDontSee('Ensure they match for proper proxy routing');
});

it('keeps the original advice when the ports field is editable', function () {
    $application = applicationWithPortMismatch(readonlyLabels: true);

    Livewire::test(General::class, ['application' => $application])
        ->assertSuccessful()
        ->assertSee('PORT mismatch detected')
        ->assertSee('Ensure they match for proper proxy routing')
        ->assertDontSee('set the port in the labels section instead');
});
