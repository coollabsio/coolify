<?php

use App\Livewire\Project\Shared\EnvironmentVariable\All;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->project = Project::factory()->create([
        'team_id' => $this->team->id,
    ]);
    $this->environment = Environment::factory()->create([
        'project_id' => $this->project->id,
    ]);

    $this->actingAs($this->user);
});

it('filters production environment variables by key case-insensitively', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
    ]);

    EnvironmentVariable::create([
        'key' => 'API_KEY',
        'value' => 'secret',
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    EnvironmentVariable::create([
        'key' => 'DATABASE_URL',
        'value' => 'postgres://example',
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    $component = Livewire::test(All::class, ['resource' => $application])
        ->call('loadEnvironmentVariables')
        ->set('search', 'api');

    expect($component->instance()->environmentVariables->pluck('key')->all())
        ->toBe(['API_KEY']);
});

it('orders environment variables by creation order or alphabetically based on the setting', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
    ]);

    EnvironmentVariable::create([
        'key' => 'ZEBRA',
        'value' => 'last-key',
        'order' => 1,
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    EnvironmentVariable::create([
        'key' => 'ALPHA',
        'value' => 'first-key',
        'order' => 2,
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    $component = Livewire::test(All::class, ['resource' => $application])
        ->call('loadEnvironmentVariables')
        ->assertSet('is_env_sorting_enabled', false);

    $userManagedKeys = $component->instance()->environmentVariables
        ->pluck('key')
        ->filter(fn (string $key) => in_array($key, ['ZEBRA', 'ALPHA'], true))
        ->values()
        ->all();

    expect($userManagedKeys)->toBe(['ZEBRA', 'ALPHA']);

    $component
        ->set('is_env_sorting_enabled', true)
        ->call('instantSave');

    $alphabeticallyOrderedKeys = $component->instance()->environmentVariables
        ->pluck('key')
        ->filter(fn (string $key) => in_array($key, ['ZEBRA', 'ALPHA'], true))
        ->values()
        ->all();

    expect($alphabeticallyOrderedKeys)
        ->toBe(['ALPHA', 'ZEBRA'])
        ->and($application->settings->fresh()->is_env_sorting_enabled)->toBeTrue();
});

it('treats production environment variable search underscore wildcards literally', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
    ]);

    EnvironmentVariable::create([
        'key' => 'API_KEY',
        'value' => 'secret',
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    EnvironmentVariable::create([
        'key' => 'APIXKEY',
        'value' => 'other-secret',
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    $component = Livewire::test(All::class, ['resource' => $application])
        ->call('loadEnvironmentVariables')
        ->set('search', 'api_key');

    expect($component->instance()->environmentVariables->pluck('key')->all())
        ->toBe(['API_KEY']);
});

it('filters preview environment variables by key case-insensitively', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
    ]);

    EnvironmentVariable::create([
        'key' => 'PREVIEW_TOKEN',
        'value' => 'preview-secret',
        'is_preview' => true,
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    EnvironmentVariable::create([
        'key' => 'OTHER_PREVIEW_VALUE',
        'value' => 'preview-other',
        'is_preview' => true,
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    $component = Livewire::test(All::class, ['resource' => $application])
        ->call('loadEnvironmentVariables')
        ->set('search', 'token');

    expect($component->instance()->environmentVariablesPreview->pluck('key')->all())
        ->toBe(['PREVIEW_TOKEN']);
});

it('filters hardcoded Docker Compose environment variables by key case-insensitively', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx
    environment:
      API_TOKEN: hardcoded-secret
      DATABASE_URL: postgres://example
YAML,
    ]);

    $component = Livewire::test(All::class, ['resource' => $service])
        ->call('loadEnvironmentVariables')
        ->set('search', 'api');

    expect($component->instance()->hardcodedEnvironmentVariables->pluck('key')->all())
        ->toBe(['API_TOKEN']);
});

it('keeps Compose self-referencing environment variables editable without changing Compose', function () {
    $dockerCompose = <<<'YAML'
services:
  app:
    image: nginx
    environment:
      API_TOKEN: ${API_TOKEN}
      LOG_LEVEL: ${LOG_LEVEL:-info}
      FIXED_VALUE: production
YAML;

    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'docker_compose_raw' => $dockerCompose,
    ]);

    foreach (['API_TOKEN', 'LOG_LEVEL'] as $key) {
        EnvironmentVariable::create([
            'key' => $key,
            'resourceable_type' => Service::class,
            'resourceable_id' => $service->id,
        ]);
    }

    $rows = Livewire::test(All::class, ['resource' => $service])
        ->call('loadEnvironmentVariables')
        ->instance()
        ->environmentVariablePageRows;

    expect($rows->where('kind', 'managed')->pluck('environmentVariable.key')->all())
        ->toBe(['API_TOKEN', 'LOG_LEVEL'])
        ->and($rows->where('kind', 'hardcoded')->pluck('environmentVariable.key')->all())
        ->toBe(['FIXED_VALUE'])
        ->and($service->fresh()->docker_compose_raw)->toBe($dockerCompose);
});

it('shows a Compose-defined value as read-only when a managed variable has the same key', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx
    environment:
      - API_TOKEN=from-compose
YAML,
    ]);

    EnvironmentVariable::create([
        'key' => 'API_TOKEN',
        'value' => 'from-environment-tab',
        'resourceable_type' => Service::class,
        'resourceable_id' => $service->id,
    ]);

    $component = Livewire::test(All::class, ['resource' => $service])
        ->call('loadEnvironmentVariables');

    expect($component->instance()->environmentVariablePageRows)
        ->toHaveCount(1)
        ->and($component->instance()->environmentVariablePageRows->first()['kind'])->toBe('hardcoded')
        ->and($component->instance()->environmentVariablePageRows->first()['environmentVariable']['value'])->toBe('from-compose');
});

it('searches service environment variables without requiring preview variables', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
    ]);

    EnvironmentVariable::create([
        'key' => 'API_KEY',
        'value' => 'secret',
        'resourceable_type' => Service::class,
        'resourceable_id' => $service->id,
    ]);

    EnvironmentVariable::create([
        'key' => 'DATABASE_URL',
        'value' => 'postgres://example',
        'resourceable_type' => Service::class,
        'resourceable_id' => $service->id,
    ]);

    $component = Livewire::test(All::class, ['resource' => $service])
        ->call('loadEnvironmentVariables')
        ->set('search', 'api')
        ->assertDontSee('Preview Deployments Environment Variables');

    expect($component->instance()->environmentVariables->pluck('key')->all())
        ->toBe(['API_KEY'])
        ->and($component->instance()->showPreview)->toBeFalse();
});

it('pins required service environment variables only while their values are missing', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx
    environment:
      HARDCODED_FIRST: configured
YAML,
    ]);

    EnvironmentVariable::create([
        'key' => 'OPTIONAL_FIRST',
        'value' => 'configured',
        'order' => 1,
        'resourceable_type' => Service::class,
        'resourceable_id' => $service->id,
    ]);

    $required = EnvironmentVariable::create([
        'key' => 'REQUIRED_SECOND',
        'value' => '',
        'order' => 2,
        'is_required' => true,
        'resourceable_type' => Service::class,
        'resourceable_id' => $service->id,
    ]);

    $component = Livewire::test(All::class, ['resource' => $service])
        ->call('loadEnvironmentVariables');

    expect($component->instance()->environmentVariablePageRows->pluck('environmentVariable.key')->all())
        ->toBe(['REQUIRED_SECOND', 'OPTIONAL_FIRST', 'HARDCODED_FIRST']);

    $required->update(['value' => 'configured']);
    $component->call('$refresh');

    expect($component->instance()->environmentVariablePageRows->pluck('environmentVariable.key')->all())
        ->toBe(['OPTIONAL_FIRST', 'REQUIRED_SECOND', 'HARDCODED_FIRST']);
});

it('does not show the empty production message when search only matches hardcoded variables', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx
    environment:
      API_TOKEN: hardcoded-secret
      DATABASE_URL: postgres://example
YAML,
    ]);

    $component = Livewire::test(All::class, ['resource' => $service])
        ->call('loadEnvironmentVariables')
        ->set('search', 'api')
        ->assertDontSee('No environment variables found');

    expect($component->instance()->hardcodedEnvironmentVariables->pluck('key')->all())
        ->toBe(['API_TOKEN']);
});

it('keeps developer view unfiltered after searching', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
    ]);

    EnvironmentVariable::create([
        'key' => 'API_KEY',
        'value' => 'secret',
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    EnvironmentVariable::create([
        'key' => 'DATABASE_URL',
        'value' => 'postgres://example',
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    $component = Livewire::test(All::class, ['resource' => $application])
        ->call('loadEnvironmentVariables')
        ->set('search', 'api')
        ->call('switch')
        ->assertSet('view', 'dev');

    expect($component->get('variables'))
        ->toContain('API_KEY=secret')
        ->toContain('DATABASE_URL=postgres://example');
});

it('does not delete non-matching variables when saving developer view after searching', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
    ]);

    EnvironmentVariable::create([
        'key' => 'API_KEY',
        'value' => 'secret',
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    EnvironmentVariable::create([
        'key' => 'DATABASE_URL',
        'value' => 'postgres://example',
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    Livewire::test(All::class, ['resource' => $application])
        ->call('loadEnvironmentVariables')
        ->set('search', 'api')
        ->call('switch')
        ->call('submit');

    expect($application->environment_variables()->pluck('key')->all())
        ->toContain('API_KEY')
        ->toContain('DATABASE_URL');
});

it('hides the preview section when search filters out all preview variables', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
    ]);

    EnvironmentVariable::create([
        'key' => 'API_KEY',
        'value' => 'secret',
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    $application->environment_variables_preview()->where('key', 'API_KEY')->delete();

    EnvironmentVariable::create([
        'key' => 'PREVIEW_TOKEN',
        'value' => 'preview-secret',
        'is_preview' => true,
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    $component = Livewire::test(All::class, ['resource' => $application])
        ->call('loadEnvironmentVariables')
        ->set('search', 'api')
        ->assertDontSee('PREVIEW_TOKEN');

    expect($component->instance()->environmentVariables->pluck('key')->all())
        ->toBe(['API_KEY'])
        ->and($component->instance()->environmentVariablesPreview)->toHaveCount(0);
});
