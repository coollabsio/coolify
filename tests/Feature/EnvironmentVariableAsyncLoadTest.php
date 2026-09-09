<?php

use App\Livewire\Project\Shared\EnvironmentVariable\All;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
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

it('does not load environment variables during mount', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
    ]);

    EnvironmentVariable::create([
        'key' => 'API_KEY',
        'value' => 'secret',
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    $component = Livewire::test(All::class, ['resource' => $application]);

    expect($component->get('readyToLoad'))->toBeFalse()
        ->and($component->instance()->environmentVariables)->toHaveCount(0)
        ->and($component->get('variables'))->toBeNull();
});

it('loads environment variables when loadEnvironmentVariables is called', function () {
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
        ->assertSee('Loading environment variables...')
        ->call('loadEnvironmentVariables')
        ->assertSet('readyToLoad', true)
        ->assertDontSee('Loading environment variables...')
        ->assertSee('API_KEY');

    expect($component->instance()->environmentVariables->pluck('key')->all())
        ->toContain('API_KEY')
        ->toContain('DATABASE_URL');
});

it('builds developer view text only after switching to developer view', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
    ]);

    EnvironmentVariable::create([
        'key' => 'API_KEY',
        'value' => 'secret',
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    $component = Livewire::test(All::class, ['resource' => $application])
        ->call('loadEnvironmentVariables');

    expect($component->get('variables'))->toBeNull();

    $component->call('switch')->assertSet('view', 'dev');

    expect($component->get('variables'))->toContain('API_KEY=secret');
});

it('is idempotent when loadEnvironmentVariables is called twice', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
    ]);

    EnvironmentVariable::create([
        'key' => 'API_KEY',
        'value' => 'secret',
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    $component = Livewire::test(All::class, ['resource' => $application])
        ->call('loadEnvironmentVariables')
        ->call('loadEnvironmentVariables')
        ->assertSet('readyToLoad', true);

    expect($component->instance()->environmentVariables->pluck('key')->all())
        ->toContain('API_KEY');
});

it('preserves generated compose variables during bulk replacement', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'build_pack' => 'dockercompose',
        'docker_compose' => <<<'YAML'
services:
  api:
    image: nginx:alpine
    environment:
      SERVICE_URL_API: /api
YAML,
    ]);

    foreach ([
        'SERVICE_URL_API' => 'https://api.example.com/api',
        'SERVICE_FQDN_API' => 'api.example.com/api',
        'OLD_VARIABLE' => 'remove-me',
    ] as $key => $value) {
        EnvironmentVariable::create([
            'key' => $key,
            'value' => $value,
            'resourceable_type' => Application::class,
            'resourceable_id' => $application->id,
        ]);
    }

    Livewire::test(All::class, ['resource' => $application])
        ->set('variables', 'NEW_VARIABLE=keep-me')
        ->call('submit')
        ->assertDispatched('success')
        ->assertNotDispatched('error');

    expect($application->environment_variables()->pluck('value', 'key')->all())
        ->toBe([
            'SERVICE_URL_API' => 'https://api.example.com/api',
            'SERVICE_FQDN_API' => 'api.example.com/api',
            'NEW_VARIABLE' => 'keep-me',
        ]);
});

it('hides generated compose variables from developer view', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'build_pack' => 'dockercompose',
    ]);

    foreach ([
        'SERVICE_URL_API' => 'https://api.example.com',
        'SERVICE_FQDN_API' => 'api.example.com',
        'API_URL' => '$SERVICE_URL_API',
    ] as $key => $value) {
        EnvironmentVariable::create([
            'key' => $key,
            'value' => $value,
            'resourceable_type' => Application::class,
            'resourceable_id' => $application->id,
        ]);
    }

    $component = Livewire::test(All::class, ['resource' => $application])
        ->call('switch');

    expect($component->get('variables'))
        ->toBe('API_URL=$SERVICE_URL_API');
});
