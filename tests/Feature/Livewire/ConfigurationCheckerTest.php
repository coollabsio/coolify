<?php

use App\Livewire\Project\Shared\ConfigurationChecker;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function configurationCheckerApplication(Environment $environment, array $attributes = []): Application
{
    return Application::factory()->create(array_merge([
        'environment_id' => $environment->id,
        'status' => 'running:healthy',
        'build_command' => 'npm run build',
        'fqdn' => 'https://example.com',
    ], $attributes));
}

function markConfigurationCheckerApplicationDeployed(Application $application): void
{
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => (string) $application->id,
        'deployment_uuid' => (string) Str::uuid(),
        'status' => 'finished',
        'commit' => 'HEAD',
    ]);

    $application->markDeploymentConfigurationApplied($deployment);
}

it('does not render the notification for preview deployment toggles', function () {
    $application = configurationCheckerApplication($this->environment);
    markConfigurationCheckerApplicationDeployed($application);

    $application->settings->update(['is_preview_deployments_enabled' => true]);

    Livewire::test(ConfigurationChecker::class, ['resource' => $application->refresh()])
        ->assertDontSee('The latest deployment is not using the current configuration')
        ->assertSet('isConfigurationChanged', false);
});

it('renders the changed configuration labels', function () {
    $application = configurationCheckerApplication($this->environment);
    markConfigurationCheckerApplicationDeployed($application);

    $application->update(['build_command' => 'pnpm build']);

    Livewire::test(ConfigurationChecker::class, ['resource' => $application->refresh()])
        ->assertSee('The latest configuration has not been applied')
        ->assertSee('Build command')
        ->assertSee('A rebuild is required.');
});

it('refreshes configuration changes when the event is received', function () {
    $application = configurationCheckerApplication($this->environment);
    markConfigurationCheckerApplicationDeployed($application);

    $component = Livewire::test(ConfigurationChecker::class, ['resource' => $application->refresh()])
        ->assertSet('isConfigurationChanged', false)
        ->assertDontSee('The latest configuration has not been applied');

    $application->update(['build_command' => 'pnpm build']);

    $component
        ->dispatch('configurationChanged')
        ->assertSet('isConfigurationChanged', true)
        ->assertSee('The latest configuration has not been applied')
        ->assertSee('Build command');
});

it('refreshes stale modal configuration diff before opening changes', function () {
    $application = configurationCheckerApplication($this->environment);
    markConfigurationCheckerApplicationDeployed($application);

    $application->update(['build_command' => 'pnpm build']);

    $component = Livewire::test(ConfigurationChecker::class, ['resource' => $application->refresh()])
        ->assertSee('Build command')
        ->assertDontSee('Start command');

    $application->update([
        'build_command' => 'npm run build',
        'start_command' => 'node server.js',
    ]);

    $component
        ->call('refreshConfigurationChanges')
        ->assertSet('isConfigurationChanged', true)
        ->assertSee('Start command')
        ->assertDontSee('Build command');
});

it('does not render environment variable secret values', function () {
    $application = configurationCheckerApplication($this->environment);
    EnvironmentVariable::create([
        'key' => 'API_TOKEN',
        'value' => 'old-secret',
        'is_buildtime' => false,
        'is_runtime' => true,
        'is_preview' => false,
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);
    markConfigurationCheckerApplicationDeployed($application->refresh());

    $application->environment_variables()->where('key', 'API_TOKEN')->first()->update(['value' => 'new-secret']);

    Livewire::test(ConfigurationChecker::class, ['resource' => $application->refresh()])
        ->assertSee('API_TOKEN')
        ->assertSee('••••••••')
        ->assertDontSee('Hidden')
        ->assertDontSee('old-secret')
        ->assertDontSee('new-secret');
});

it('renders added environment variables as set without exposing secret values', function () {
    $application = configurationCheckerApplication($this->environment);
    markConfigurationCheckerApplicationDeployed($application);

    EnvironmentVariable::create([
        'key' => 'API_TOKEN',
        'value' => 'new-secret',
        'is_buildtime' => false,
        'is_runtime' => true,
        'is_preview' => false,
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    Livewire::test(ConfigurationChecker::class, ['resource' => $application->refresh()])
        ->assertSee('API_TOKEN')
        ->assertSee('From')
        ->assertSee('-')
        ->assertSee('To')
        ->assertSee('••••••••')
        ->assertDontSee('Hidden')
        ->assertDontSee('new-secret');
});
