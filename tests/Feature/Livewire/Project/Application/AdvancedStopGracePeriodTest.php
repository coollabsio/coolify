<?php

use App\Livewire\Project\Application\Advanced;
use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function createApplicationForAdvancedStopGracePeriodTest(): Application
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return Application::create([
        'name' => 'stop-grace-period-test-app',
        'git_repository' => 'https://github.com/coollabsio/coolify',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $server->standaloneDockers()->firstOrFail()->id,
        'destination_type' => $server->standaloneDockers()->firstOrFail()->getMorphClass(),
    ]);
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('saves a valid stop grace period', function () {
    $application = createApplicationForAdvancedStopGracePeriodTest();

    Livewire::test(Advanced::class, ['application' => $application])
        ->set('stopGracePeriod', '300')
        ->call('saveStopGracePeriod')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect($application->settings()->first()->stop_grace_period)->toBe(300);
});

it('dispatches configuration changed when advanced settings are saved', function () {
    $application = createApplicationForAdvancedStopGracePeriodTest();

    Livewire::test(Advanced::class, ['application' => $application])
        ->set('includeSourceCommitInBuild', true)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('configurationChanged');
});

it('clears the stop grace period when submitted empty', function () {
    $application = createApplicationForAdvancedStopGracePeriodTest();
    $application->settings->update(['stop_grace_period' => 300]);

    Livewire::test(Advanced::class, ['application' => $application->fresh()])
        ->set('stopGracePeriod', '')
        ->call('saveStopGracePeriod')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect($application->settings()->first()->stop_grace_period)->toBeNull();
});

it('rejects invalid stop grace periods', function (string $value, string $rule) {
    $application = createApplicationForAdvancedStopGracePeriodTest();

    Livewire::test(Advanced::class, ['application' => $application])
        ->set('stopGracePeriod', $value)
        ->call('saveStopGracePeriod')
        ->assertHasErrors(['stopGracePeriod' => [$rule]]);

    expect($application->settings()->first()->stop_grace_period)->toBeNull();
})->with([
    'below minimum' => ['0', 'min'],
    'above maximum' => [(string) (MAX_STOP_GRACE_PERIOD_SECONDS + 1), 'max'],
    'malformed integer' => ['10abc', 'integer'],
    'decimal' => ['1.9', 'integer'],
]);

it('uses one second deployment timeout in local only when stop grace period is unset', function () {
    config(['app.env' => 'local']);

    $setting = new ApplicationSetting;

    expect($setting->deploymentStopGracePeriodSeconds())->toBe(MIN_STOP_GRACE_PERIOD_SECONDS);

    $setting->stop_grace_period = 10;

    expect($setting->deploymentStopGracePeriodSeconds())->toBe(10);
});

it('uses default deployment timeout outside local when stop grace period is unset', function () {
    config(['app.env' => 'production']);

    $setting = new ApplicationSetting;

    expect($setting->deploymentStopGracePeriodSeconds())->toBe(DEFAULT_STOP_GRACE_PERIOD_SECONDS);
});
