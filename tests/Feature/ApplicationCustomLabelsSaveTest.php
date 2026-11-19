<?php

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
        'build_pack' => 'dockerfile',
        'ports_exposes' => '8000',
        'fqdn' => 'http://app.example.com',
    ]);
});

/**
 * Regression for #6375: labels with a character that is not ASCII were replaced with the Coolify defaults after save.
 */
test('keeps manually edited labels with non-ASCII text after switching to manual labels and saving', function () {
    $customLabels = "traefik.enable=true\ntraefik.http.middlewares.auth.basicauth.realm=Zugang für Admins – privat\ntraefik.http.middlewares.auth.basicauth.users=test:\$\$apr1\$\$H6uskkkW\$\$IgXLP6ewTrSuBkTrqE8wj/";

    $component = Livewire::test(General::class, ['application' => $this->application->fresh()])
        ->set('isContainerLabelReadonlyEnabled', false)
        ->call('instantSave')
        ->set('customLabels', $customLabels)
        ->call('submit')
        ->assertHasNoErrors();

    expect($component->get('customLabels'))->toBe($customLabels)
        ->and($this->application->fresh()->parseContainerLabels())->toBe($customLabels);

    Livewire::test(General::class, ['application' => $this->application->fresh()])
        ->assertSet('customLabels', $customLabels);
});
