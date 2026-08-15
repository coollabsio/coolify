<?php

use App\Livewire\Project\Shared\EnvironmentVariable\All;
use App\Livewire\Project\Shared\EnvironmentVariable\Show;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\Yaml\Yaml;

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

it('keeps a self-referencing Compose environment variable editable without changing Compose', function () {
    $dockerCompose = <<<'YAML'
services:
  app:
    image: nginx
    environment:
      - API_TOKEN=${API_TOKEN}
YAML;

    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'docker_compose_raw' => $dockerCompose,
        'docker_compose' => $dockerCompose,
    ]);

    $environmentVariable = EnvironmentVariable::create([
        'key' => 'API_TOKEN',
        'value' => 'from-environment-tab',
        'resourceable_type' => Service::class,
        'resourceable_id' => $service->id,
    ]);

    $component = Livewire::test(All::class, ['resource' => $service])
        ->call('loadEnvironmentVariables');

    expect($component->instance()->environmentVariablePageRows)
        ->toHaveCount(1)
        ->and($component->instance()->environmentVariablePageRows->first()['kind'])->toBe('managed')
        ->and($component->instance()->environmentVariablePageRows->first()['environmentVariable']->is($environmentVariable))->toBeTrue()
        ->and($component->instance()->environmentVariablePageRows->first()['composeInfo'])->toBe([
            'services' => ['app'],
            'default' => null,
            'operator' => null,
            'required' => false,
        ])
        ->and($service->fresh()->docker_compose_raw)->toBe($dockerCompose)
        ->and($service->fresh()->docker_compose)->toBe($dockerCompose);
});

it('keeps a self-referencing Compose variable with a default editable', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx
    environment:
      LOG_LEVEL: ${LOG_LEVEL:-info}
YAML,
    ]);

    EnvironmentVariable::create([
        'key' => 'LOG_LEVEL',
        'value' => 'debug',
        'resourceable_type' => Service::class,
        'resourceable_id' => $service->id,
    ]);

    $component = Livewire::test(All::class, ['resource' => $service])
        ->call('loadEnvironmentVariables');

    expect($component->instance()->environmentVariablePageRows)
        ->toHaveCount(1)
        ->and($component->instance()->environmentVariablePageRows->first()['kind'])->toBe('managed')
        ->and($component->instance()->environmentVariablePageRows->first()['composeInfo'])->toBe([
            'services' => ['app'],
            'default' => 'info',
            'operator' => ':-',
            'required' => false,
        ]);
});

it('describes derived Compose assignments without replacing them', function () {
    $dockerCompose = <<<'YAML'
services:
  api:
    image: nginx
    environment:
      API_URL: https://${API_HOST}/v1
YAML;

    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'docker_compose_raw' => $dockerCompose,
        'docker_compose' => $dockerCompose,
    ]);

    EnvironmentVariable::create([
        'key' => 'API_HOST',
        'value' => 'api.example.com',
        'resourceable_type' => Service::class,
        'resourceable_id' => $service->id,
    ]);

    $rows = Livewire::test(All::class, ['resource' => $service])
        ->call('loadEnvironmentVariables')
        ->instance()
        ->environmentVariablePageRows;

    expect($rows)->toHaveCount(2)
        ->and($rows->firstWhere('kind', 'managed')['composeInfo'])->toBe([
            'services' => ['api'],
            'default' => null,
            'operator' => null,
            'required' => false,
        ])
        ->and($rows->firstWhere('kind', 'hardcoded')['environmentVariable']['compose_type'])->toBe('derived')
        ->and($rows->firstWhere('kind', 'hardcoded')['environmentVariable']['references'])->toBe(['API_HOST'])
        ->and($service->fresh()->docker_compose_raw)->toBe($dockerCompose)
        ->and($service->fresh()->docker_compose)->toBe($dockerCompose);

    Livewire::test(All::class, ['resource' => $service])
        ->call('loadEnvironmentVariables')
        ->set('serviceFilters', ['api'])
        ->set('variableFilters', ['managed'])
        ->set('environmentFilter', 'production')
        ->call('focusComposeEnvironmentVariable', 'API_HOST')
        ->assertSet('search', 'API_HOST')
        ->assertSet('serviceFilters', [])
        ->assertSet('variableFilters', [])
        ->assertSet('environmentFilter', 'all')
        ->assertSet('page', 1);
});

it('creates editable inputs referenced inside derived Compose values', function () {
    $dockerCompose = <<<'YAML'
services:
  api:
    image: nginx
    environment:
      API_URL: ${API_SCHEME}://${API_HOST}/v1
YAML;

    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => Server::factory()->create(['team_id' => $this->team->id])->id,
        'docker_compose_raw' => $dockerCompose,
    ]);

    $service->parse();

    expect($service->environment_variables()->where('key', 'API_SCHEME')->exists())->toBeTrue()
        ->and($service->environment_variables()->where('key', 'API_HOST')->exists())->toBeTrue()
        ->and(Yaml::parse($service->fresh()->docker_compose_raw))->toBe(Yaml::parse($dockerCompose));
});

it('keeps editable and literal rows when services use the same key differently', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'docker_compose_raw' => <<<'YAML'
services:
  api:
    image: nginx
    environment:
      FOO: ${FOO}
  worker:
    image: nginx
    environment:
      FOO: fixed
YAML,
    ]);

    EnvironmentVariable::create([
        'key' => 'FOO',
        'value' => 'editable',
        'resourceable_type' => Service::class,
        'resourceable_id' => $service->id,
    ]);

    $rows = Livewire::test(All::class, ['resource' => $service])
        ->call('loadEnvironmentVariables')
        ->instance()
        ->environmentVariablePageRows;

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('kind')->all())->toBe(['managed', 'hardcoded']);
});

it('treats escaped Compose dollars as a literal value', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx
    environment:
      SHELL_EXPRESSION: $${NOT_AN_INPUT}
YAML,
    ]);

    $row = Livewire::test(All::class, ['resource' => $service])
        ->call('loadEnvironmentVariables')
        ->instance()
        ->environmentVariablePageRows
        ->first();

    expect($row['kind'])->toBe('hardcoded')
        ->and($row['environmentVariable']['compose_type'])->toBe('literal')
        ->and($row['environmentVariable']['references'])->toBe([]);
});

it('does not use a required Compose expression message as its value', function () {
    $dockerCompose = <<<'YAML'
services:
  app:
    image: nginx
    environment:
      API_TOKEN: ${API_TOKEN:?API_TOKEN must be set}
YAML;

    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => Server::factory()->create(['team_id' => $this->team->id])->id,
        'docker_compose_raw' => $dockerCompose,
    ]);

    $parsedCompose = $service->parse();
    $environmentVariable = $service->environment_variables()->where('key', 'API_TOKEN')->firstOrFail();

    expect($environmentVariable->value)->toBeNull()
        ->and((bool) $environmentVariable->is_required)->toBeTrue()
        ->and(data_get($parsedCompose, 'services.app.environment.API_TOKEN'))->toBe('${API_TOKEN:?API_TOKEN must be set}')
        ->and(Yaml::parse($service->fresh()->docker_compose_raw))->toBe(Yaml::parse($dockerCompose));
});

it('synchronizes required metadata when Compose expressions change', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $server->id,
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx
    environment:
      API_TOKEN: ${API_TOKEN:?API_TOKEN must be set}
YAML,
    ]);
    $environmentVariable = EnvironmentVariable::create([
        'key' => 'API_TOKEN',
        'value' => 'configured',
        'is_required' => false,
        'resourceable_type' => Service::class,
        'resourceable_id' => $service->id,
    ]);

    $service->parse();
    expect((bool) $environmentVariable->fresh()->is_required)->toBeTrue();

    $service->update([
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx
    environment:
      API_TOKEN: ${API_TOKEN:-optional}
YAML,
    ]);
    $service->parse();

    expect((bool) $environmentVariable->fresh()->is_required)->toBeFalse();

    $service->update([
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx
YAML,
    ]);
    $service->parse();

    expect((bool) $environmentVariable->fresh()->is_required)->toBeFalse();
});

it('rejects renaming a Compose-linked variable through Livewire state', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => Server::factory()->create(['team_id' => $this->team->id])->id,
        'docker_compose_raw' => "services:\n  app:\n    image: nginx\n    environment:\n      API_TOKEN: \${API_TOKEN}\n",
    ]);
    $environmentVariable = EnvironmentVariable::create([
        'key' => 'API_TOKEN',
        'value' => 'secret',
        'resourceable_type' => Service::class,
        'resourceable_id' => $service->id,
    ]);

    Livewire::test(Show::class, [
        'env' => $environmentVariable,
        'type' => 'service',
        'composeInfo' => [
            'services' => ['app'],
            'default' => null,
            'operator' => null,
            'required' => false,
        ],
    ])->call('loadValues')
        ->set('composeInfo', null)
        ->set('key', 'RENAMED_TOKEN')
        ->call('submit');

    expect($environmentVariable->fresh()->key)->toBe('API_TOKEN');
});

it('creates an editable variable for bare Compose passthrough syntax without rewriting Compose', function () {
    $dockerCompose = <<<'YAML'
services:
  app:
    image: nginx
    environment:
      - API_TOKEN
      - EMPTY_VALUE=
YAML;

    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => Server::factory()->create(['team_id' => $this->team->id])->id,
        'docker_compose_raw' => $dockerCompose,
    ]);
    $storedDockerCompose = $service->docker_compose_raw;

    $service->parse();
    $rows = Livewire::test(All::class, ['resource' => $service->fresh()])
        ->call('loadEnvironmentVariables')
        ->instance()
        ->environmentVariablePageRows;

    expect($service->environment_variables()->where('key', 'API_TOKEN')->exists())->toBeTrue()
        ->and($service->environment_variables()->where('key', 'EMPTY_VALUE')->exists())->toBeFalse()
        ->and($rows)->toHaveCount(2)
        ->and($rows->first()['kind'])->toBe('managed')
        ->and($rows->first()['composeInfo'])->toBe([
            'services' => ['app'],
            'default' => null,
            'operator' => null,
            'required' => false,
        ])
        ->and($rows->last()['kind'])->toBe('hardcoded')
        ->and($rows->last()['environmentVariable']['key'])->toBe('EMPTY_VALUE')
        ->and($rows->last()['environmentVariable']['compose_type'])->toBe('literal')
        ->and(Yaml::parse($service->fresh()->docker_compose_raw))->toBe(Yaml::parse($storedDockerCompose));
});

it('presents a Compose fallback as a placeholder and restores it when cleared', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => Server::factory()->create(['team_id' => $this->team->id])->id,
        'docker_compose_raw' => "services:\n  app:\n    image: nginx\n    environment:\n      LOG_LEVEL: \${LOG_LEVEL:-info}\n",
    ]);
    $environmentVariable = EnvironmentVariable::create([
        'key' => 'LOG_LEVEL',
        'value' => 'info',
        'resourceable_type' => Service::class,
        'resourceable_id' => $service->id,
    ]);

    $component = Livewire::test(Show::class, [
        'env' => $environmentVariable,
        'type' => 'service',
        'composeInfo' => [
            'services' => ['app'],
            'default' => 'info',
            'operator' => ':-',
            'required' => false,
        ],
    ])->call('loadValues');

    $component->assertSet('value', null)
        ->set('value', 'debug')
        ->call('submit')
        ->set('value', null)
        ->call('submit');

    expect($environmentVariable->fresh()->value)->toBe('info');
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
