<?php

use App\Livewire\Analytics;
use App\Livewire\Dashboard\TrafficAnalytics;
use App\Livewire\Project\Application\Analytics as ApplicationAnalytics;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The chart id is printed into inline scripts, so clients must not change it.
 */
beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);
    $this->user = User::factory()->create();
    $this->team = $this->user->teams()->first();
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings->update(['is_traffic_analytics_enabled' => false]);
});

it('locks the scoped server and chart id of the analytics page', function (string $property, string $value) {
    $component = loadLazy(Livewire::test(Analytics::class, ['scopedServerUuid' => $this->server->uuid]));

    expect(fn () => $component->set($property, $value))->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    'scoped server' => ['scopedServerUuid', 'other-server'],
    'chart id' => ['chartId', "x');alert(1);//"],
]);

it('locks the server uuid and chart id of the resource analytics tab', function (string $property, string $value) {
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $destination = StandaloneDocker::where('server_id', $this->server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $this->server->id, 'network' => 'coolify-locked-test']);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $component = Livewire::test(ApplicationAnalytics::class, ['application' => $application, 'lazy' => false]);

    expect(fn () => $component->set($property, $value))->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    'analytics server' => ['analyticsServerUuid', 'other-server'],
    'chart id' => ['chartId', "x');alert(1);//"],
]);

it('locks the chart id of the dashboard traffic summary', function () {
    $component = loadLazy(Livewire::test(TrafficAnalytics::class));

    expect(fn () => $component->set('chartId', "x');alert(1);//"))->toThrow(CannotUpdateLockedPropertyException::class);
});
