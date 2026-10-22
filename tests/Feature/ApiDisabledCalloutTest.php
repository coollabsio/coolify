<?php

use App\Livewire\Project\Shared\Webhooks;
use App\Livewire\Security\ApiTokens;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => false]);

    $this->rootTeam = Team::factory()->create(['id' => 0]);
    $this->instanceAdmin = User::factory()->create();
    $this->rootTeam->members()->attach($this->instanceAdmin->id, ['role' => 'admin']);

    $this->team = Team::factory()->create();
    $this->teamAdmin = User::factory()->create();
    $this->team->members()->attach($this->teamAdmin->id, ['role' => 'admin']);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
});

afterEach(function () {
    Server::flushIdentityMap();
});

it('links instance admins to the API settings when the API is disabled', function (string $component) {
    $this->actingAs($this->instanceAdmin);
    session(['currentTeam' => $this->rootTeam]);

    $params = $component === Webhooks::class ? ['resource' => $this->application] : [];

    Livewire::test($component, $params)
        ->assertSee('API access is turned off')
        ->assertSee(route('settings.advanced'))
        ->assertSee('Enable API access');
})->with(['webhooks' => Webhooks::class, 'api tokens' => ApiTokens::class]);

it('tells other users to ask an instance admin when the API is disabled', function (string $component) {
    $this->actingAs($this->teamAdmin);
    session(['currentTeam' => $this->team]);

    $params = $component === Webhooks::class ? ['resource' => $this->application] : [];

    Livewire::test($component, $params)
        ->assertSee('API access is turned off')
        ->assertSee('Ask an instance admin to enable API access.')
        ->assertDontSee(route('settings.advanced'));
})->with(['webhooks' => Webhooks::class, 'api tokens' => ApiTokens::class]);

it('does not show the API notice on the webhooks page when the API is enabled', function () {
    InstanceSettings::query()->whereKey(0)->update(['is_api_enabled' => true]);
    Once::flush();
    $this->actingAs($this->teamAdmin);
    session(['currentTeam' => $this->team]);

    Livewire::test(Webhooks::class, ['resource' => $this->application])
        ->assertDontSee('API access is turned off');
});

it('lets users create API tokens for MCP while the REST API is disabled', function () {
    $this->actingAs($this->teamAdmin);
    session(['currentTeam' => $this->team]);

    Livewire::test(ApiTokens::class)
        ->assertSee('API access is turned off')
        ->assertSee('Create token')
        ->set('description', 'MCP client')
        ->call('addNewToken')
        ->assertHasNoErrors();

    expect($this->teamAdmin->tokens()->where('name', 'MCP client')->exists())->toBeTrue();
});
