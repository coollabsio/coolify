<?php

use App\Events\ApplicationConfigurationChanged;
use App\Livewire\Project\Service\Configuration;
use App\Livewire\Project\Shared\ConfigurationChecker;
use App\Livewire\Project\Shared\EnvironmentVariable\Show;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
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

it('renders the changed configuration labels without a second backend request', function () {
    $application = configurationCheckerApplication($this->environment);
    markConfigurationCheckerApplicationDeployed($application);

    $application->update(['build_command' => 'pnpm build']);

    Livewire::test(ConfigurationChecker::class, ['resource' => $application->refresh()])
        ->assertSee('The latest configuration has not been applied')
        ->assertSee('Rebuild required.')
        ->assertSee('Build command');

    $view = file_get_contents(resource_path('views/livewire/project/shared/configuration-checker.blade.php'));

    expect($view)
        ->toContain(':compact-after="5000"')
        ->toContain('position="top-right"')
        ->toContain(':compact-storage-key="$compactStorageKey"')
        ->toContain('wire:key="configuration-warning-{{ $currentConfigurationHash }}"')
        ->toContain('x-on:click="configurationDiffModalOpen = true"')
        ->not->toContain('$wire.refreshConfigurationChanges()');
});

it('supports timed compact popup notifications', function () {
    $view = file_get_contents(resource_path('views/components/popup-small.blade.php'));

    expect($view)
        ->toContain("\$position === 'top-right' ? 'top-16' : 'bottom-4'")
        ->toContain('compactAfter')
        ->toContain('compactStorageKey')
        ->toContain("localStorage.setItem(this.storageKey, 'compact')")
        ->toContain("localStorage.setItem(this.storageKey, 'icon')")
        ->toContain('localStorage.removeItem(key)')
        ->not->toContain('<template x-teleport="body">')
        ->toContain('compact = true')
        ->toContain('@click="restore()"')
        ->toContain('@click.stop="minimizeToIcon()"')
        ->toContain('<template x-if="iconOnly">')
        ->toContain('<template x-if="!iconOnly">')
        ->not->toContain('<button x-show="iconOnly"')
        ->not->toContain('<div x-show="!iconOnly"')
        ->not->toContain(':class="iconOnly')
        ->toContain('x-show="!compact"')
        ->toContain("'w-[calc(100vw-2rem)] max-w-sm cursor-pointer'");
});

it('warns when a service has missing required environment variables', function () {
    $service = Service::factory()->create(['environment_id' => $this->environment->id]);
    $service->environment_variables()->create([
        'key' => 'PLUNK_API_KEY',
        'value' => '',
        'is_required' => true,
    ]);

    Livewire::test(ConfigurationChecker::class, ['resource' => $service])
        ->assertSet('missingRequiredEnvironmentVariableCount', 1)
        ->assertSee('Required environment variable missing')
        ->assertSee('PLUNK_API_KEY')
        ->assertSee('Open environment variables');

});

it('broadcasts a configuration update after a required service variable is set', function () {
    Event::fake([ApplicationConfigurationChanged::class]);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $server->id,
    ]);
    $environmentVariable = $service->environment_variables()->create([
        'key' => 'PLUNK_API_KEY',
        'value' => '',
        'is_required' => true,
    ]);

    Livewire::test(Show::class, ['env' => $environmentVariable, 'type' => 'service'])
        ->call('loadValues')
        ->set('value', 'secret')
        ->call('submit');

    Event::assertDispatched(
        ApplicationConfigurationChanged::class,
        fn (ApplicationConfigurationChanged $event): bool => $event->teamId === $this->team->id,
    );
});

it('refreshes the service configuration when a websocket configuration event arrives', function () {
    $listeners = app(Configuration::class)->getListeners();

    expect($listeners)
        ->toHaveKey("echo-private:team.{$this->team->id},ApplicationConfigurationChanged", 'refreshServices')
        ->toHaveKey('configurationChanged', 'refreshServices');
});

it('marks the service environment variables menu when required values are missing', function () {
    $configuration = file_get_contents(resource_path('views/livewire/project/service/configuration.blade.php'));
    $sidebar = file_get_contents(resource_path('views/components/service/configuration-sidebar.blade.php'));

    expect($configuration)
        ->toContain("'hasWarning' => ! \$service->isDeployable")
        ->toContain('title="Required environment variables missing"')
        ->and($sidebar)
        ->toContain("'hasWarning' => ! \$service->isDeployable")
        ->toContain('title="Required environment variables missing"');
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

it('shows domain changes when the domain page dispatches a configuration change', function () {
    $application = configurationCheckerApplication($this->environment);
    markConfigurationCheckerApplicationDeployed($application);

    $component = Livewire::test(ConfigurationChecker::class, ['resource' => $application->refresh()])
        ->assertSet('isConfigurationChanged', false);

    $application->update(['fqdn' => 'https://changed.example.com']);

    $component
        ->dispatch('configurationChanged')
        ->assertSet('isConfigurationChanged', true)
        ->assertSee('Domains')
        ->assertSee('https://changed.example.com');
});

it('shows noindex changes when the domains page dispatches a configuration change', function () {
    $application = configurationCheckerApplication($this->environment, [
        'fqdn' => 'https://example.com,https://staging.example.com',
    ]);
    markConfigurationCheckerApplicationDeployed($application);

    $component = Livewire::test(ConfigurationChecker::class, ['resource' => $application->refresh()])
        ->assertSet('isConfigurationChanged', false);

    $application->setNoindexDomains(['https://staging.example.com']);
    $application->save();

    $component
        ->dispatch('configurationChanged')
        ->assertSet('isConfigurationChanged', true)
        ->assertSee('The latest configuration has not been applied')
        ->assertSee('Search engine indexing')
        ->assertSee('Redeploy to apply.');
});

it('shows an unapplied configuration warning after a directory mount is added', function () {
    $application = configurationCheckerApplication($this->environment);
    markConfigurationCheckerApplicationDeployed($application);

    $component = Livewire::test(ConfigurationChecker::class, ['resource' => $application->refresh()])
        ->assertSet('isConfigurationChanged', false);

    LocalFileVolume::withoutEvents(fn () => LocalFileVolume::forceCreate([
        'uuid' => (string) Str::uuid(),
        'fs_path' => application_configuration_dir().'/'.$application->uuid.'/data',
        'mount_path' => '/app/data',
        'is_directory' => true,
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]));

    $component
        ->dispatch('configurationChanged')
        ->assertSet('isConfigurationChanged', true)
        ->assertSee('The latest configuration has not been applied')
        ->assertSee('Redeploy to apply.')
        ->assertSee('Directory mount');
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
        ->dispatch('configurationChanged')
        ->assertSet('isConfigurationChanged', true)
        ->assertSee('Start command')
        ->assertDontSee('Build command');
});

it('includes full configuration change rows in the initial Livewire snapshot', function () {
    $application = configurationCheckerApplication($this->environment);
    markConfigurationCheckerApplicationDeployed($application);
    $application->update(['build_command' => 'pnpm build']);

    $component = Livewire::test(ConfigurationChecker::class, ['resource' => $application->refresh()]);

    expect($component->get('isConfigurationChanged'))->toBeTrue()
        ->and($component->get('configurationDiff'))->toHaveKey('changes')
        ->and(data_get($component->get('configurationDiff'), 'changes'))->not->toBeEmpty();
});

it('redacts unlocked environment values for team members in the change list', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

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

    $component = Livewire::test(ConfigurationChecker::class, ['resource' => $application->refresh()]);

    $envChange = collect(data_get($component->get('configurationDiff'), 'changes', []))
        ->first(fn (array $change): bool => str_contains((string) data_get($change, 'key'), 'API_TOKEN')
            || str_contains((string) data_get($change, 'label'), 'API_TOKEN'));

    expect($envChange)->not->toBeNull()
        ->and(data_get($envChange, 'old_display_value'))->toBe('••••••••')
        ->and(data_get($envChange, 'new_display_value'))->toBe('••••••••')
        ->and(data_get($envChange, 'old_full_value'))->toBeNull()
        ->and(data_get($envChange, 'new_full_value'))->toBeNull();
});

it('redacts newly added environment values for team members', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

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

    $component = Livewire::test(ConfigurationChecker::class, ['resource' => $application->refresh()])
        ->assertSee('API_TOKEN')
        ->assertSee('Current')
        ->assertSee('New');

    $envChange = collect(data_get($component->get('configurationDiff'), 'changes', []))
        ->first(fn (array $change): bool => str_contains((string) data_get($change, 'key'), 'API_TOKEN')
            || str_contains((string) data_get($change, 'label'), 'API_TOKEN'));

    expect($envChange)->not->toBeNull()
        ->and(data_get($envChange, 'old_display_value'))->toBe('-')
        ->and(data_get($envChange, 'new_display_value'))->toBe('••••••••')
        ->and(data_get($envChange, 'type'))->toBe('added');
});
