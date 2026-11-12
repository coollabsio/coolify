<?php

use App\Livewire\Fleet\Metrics as FleetMetrics;
use App\Livewire\Project\Shared\Metrics as ResourceMetrics;
use App\Livewire\Server\Charts;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Every metrics poll runs SSH commands that fetch the full range. These servers have metrics
 * off, so loadData() runs without SSH; its refreshChartData event shows that a fetch ran.
 */
beforeEach(function () {
    Queue::fake();
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
});

dataset('range metrics components', [
    'server metrics' => [fn () => [
        Livewire::test(Charts::class, ['server_uuid' => $this->server->uuid]),
        'refreshChartData-server-metrics',
    ]],
    'resource metrics' => [fn () => [
        Livewire::test(ResourceMetrics::class, ['resource' => Application::factory()->create([
            'environment_id' => Environment::factory()->create([
                'project_id' => Project::factory()->create(['team_id' => $this->team->id])->id,
            ])->id,
            'destination_id' => StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail()->id,
            'destination_type' => StandaloneDocker::class,
        ])]),
        'refreshChartData-metrics-cpu',
    ]],
]);

it('refreshes short ranges live every five seconds by default', function (Closure $build) {
    /** @var Testable $component */
    [$component, $event] = $build->call($this);

    $component->assertSet('live', true);
    expect($component->instance()->pollSeconds())->toBe(5);

    $component->call('pollData')->assertDispatched($event);
})->with('range metrics components');

it('loads long ranges once and does not poll them by default', function (Closure $build) {
    /** @var Testable $component */
    [$component, $event] = $build->call($this);

    $component->set('interval', 43200)->call('setInterval')
        ->assertSet('live', false)
        ->assertDispatched($event);
    expect($component->instance()->pollSeconds())->toBeNull();

    $component->call('pollData')->assertNotDispatched($event);
})->with('range metrics components');

it('refreshes a long range every minute after the user turns live refresh on', function (Closure $build) {
    /** @var Testable $component */
    [$component, $event] = $build->call($this);

    $component->set('interval', 10080)->call('setInterval')
        ->set('live', true)
        ->assertDispatched($event);
    expect($component->instance()->pollSeconds())->toBe(60);

    // A client that polls faster than the range allows gets no new data.
    $component->call('pollData')->assertNotDispatched($event);

    $this->travel(61)->seconds();
    $component->call('pollData')->assertDispatched($event);
})->with('range metrics components');

it('turns live refresh back on when the user returns to a short range', function (Closure $build) {
    /** @var Testable $component */
    [$component] = $build->call($this);

    $component->set('interval', 720)->assertSet('live', false)
        ->set('interval', 30)->assertSet('live', true);
    expect($component->instance()->pollSeconds())->toBe(5);
})->with('range metrics components');

it('does not poll fleet metrics until the user turns live refresh on', function () {
    $component = loadLazy(Livewire::test(FleetMetrics::class))->assertSet('live', false);
    expect($component->instance()->pollSeconds())->toBeNull();
    $component->call('pollData')->assertNotDispatched('refreshChartData-fleet-metrics');

    $component->set('live', true)->assertDispatched('refreshChartData-fleet-metrics');
    expect($component->instance()->pollSeconds())->toBe(60);
    $component->call('pollData')->assertNotDispatched('refreshChartData-fleet-metrics');

    $this->travel(61)->seconds();
    $component->call('pollData')->assertDispatched('refreshChartData-fleet-metrics');
});

it('pauses fleet live refresh when the user picks another range', function () {
    loadLazy(Livewire::test(FleetMetrics::class))
        ->set('live', true)
        ->call('setRange', '30d')
        ->assertSet('live', false);
});
