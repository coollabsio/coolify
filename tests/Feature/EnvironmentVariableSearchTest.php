<?php

use App\Livewire\Project\Shared\EnvironmentVariable\All;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

it('builds developer view data only when developer view is opened', function () {
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
        ->assertSet('view', 'normal')
        ->assertSet('variables', null)
        ->call('switch')
        ->assertSet('view', 'dev');

    expect($component->get('variables'))
        ->toContain('API_KEY=secret');
});

it('adds an environment variable from normal view without developer view data', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
    ]);

    $component = Livewire::test(All::class, ['resource' => $application])
        ->assertSet('variables', null)
        ->call('submit', [
            'key' => 'NEW_KEY',
            'value' => 'new-secret',
            'is_multiline' => false,
            'is_literal' => false,
            'is_runtime' => true,
            'is_buildtime' => true,
        ]);

    expect($application->environment_variables()->where('key', 'NEW_KEY')->value('value'))
        ->toBe('new-secret')
        ->and($component->get('variables'))
        ->toBeNull();
});

it('does not change preview variables when preview developer view data is not loaded', function () {
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
        'key' => 'PREVIEW_ONLY',
        'value' => 'preview-secret',
        'is_preview' => true,
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    Livewire::test(All::class, ['resource' => $application])
        ->assertSet('variablesPreview', null)
        ->set('variables', 'API_KEY=updated-secret')
        ->call('submit');

    expect($application->environment_variables()->where('key', 'API_KEY')->value('value'))
        ->toBe('updated-secret')
        ->and($application->environment_variables_preview()->where('key', 'PREVIEW_ONLY')->value('value'))
        ->toBe('preview-secret');
});

it('renders many environment variables without reloading the resource for every row', function () {
    $countQueriesForRows = function (int $rows): int {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
        ]);

        for ($i = 1; $i <= $rows; $i++) {
            EnvironmentVariable::create([
                'key' => 'KEY_'.$i,
                'value' => 'secret-'.$i,
                'resourceable_type' => Application::class,
                'resourceable_id' => $application->id,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::test(All::class, ['resource' => $application])
            ->assertSee('KEY_1')
            ->assertSee('KEY_'.$rows);

        $queryCount = count(DB::getQueryLog());

        DB::disableQueryLog();
        DB::flushQueryLog();

        return $queryCount;
    };

    $oneVariableQueries = $countQueriesForRows(1);
    $manyVariableQueries = $countQueriesForRows(20);

    expect($manyVariableQueries - $oneVariableQueries)->toBeLessThan(5);
});

it('resolves shared variable autocomplete data once for many environment variables', function () {
    session(['currentTeam' => $this->team]);

    SharedEnvironmentVariable::create([
        'key' => 'TEAM_API_TOKEN',
        'value' => 'team-secret',
        'type' => 'team',
        'team_id' => $this->team->id,
    ]);

    $countQueriesForRows = function (int $rows): int {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
        ]);

        for ($i = 1; $i <= $rows; $i++) {
            EnvironmentVariable::create([
                'key' => 'KEY_'.$i,
                'value' => 'secret-'.$i,
                'resourceable_type' => Application::class,
                'resourceable_id' => $application->id,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::test(All::class, ['resource' => $application])
            ->assertSee('KEY_1')
            ->assertSee('KEY_'.$rows)
            ->assertSee('TEAM_API_TOKEN');

        $queryCount = count(DB::getQueryLog());

        DB::disableQueryLog();
        DB::flushQueryLog();

        return $queryCount;
    };

    $oneVariableQueries = $countQueriesForRows(1);
    $manyVariableQueries = $countQueriesForRows(20);

    expect($manyVariableQueries - $oneVariableQueries)->toBeLessThan(5);
});

it('scopes shared variable autocomplete data to the current route and team', function () {
    session(['currentTeam' => $this->team]);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create([
            'server_id' => $server->id,
            'network' => 'coolify-test-'.$server->id,
        ]);
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'server_id' => $server->id,
    ]);

    SharedEnvironmentVariable::create([
        'key' => 'TEAM_API_TOKEN',
        'value' => 'team-secret',
        'type' => 'team',
        'team_id' => $this->team->id,
    ]);
    SharedEnvironmentVariable::create([
        'key' => 'PROJECT_API_TOKEN',
        'value' => 'project-secret',
        'type' => 'project',
        'project_id' => $this->project->id,
        'team_id' => $this->team->id,
    ]);
    SharedEnvironmentVariable::create([
        'key' => 'ENVIRONMENT_API_TOKEN',
        'value' => 'environment-secret',
        'type' => 'environment',
        'environment_id' => $this->environment->id,
        'project_id' => $this->project->id,
        'team_id' => $this->team->id,
    ]);
    SharedEnvironmentVariable::create([
        'key' => 'SERVER_API_TOKEN',
        'value' => 'server-secret',
        'type' => 'server',
        'server_id' => $server->id,
        'team_id' => $this->team->id,
    ]);

    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $this->team->id]);
    $otherEnvironment = Environment::factory()->create(['project_id' => $this->project->id]);
    $otherServer = Server::factory()->create(['team_id' => $this->team->id]);

    SharedEnvironmentVariable::create([
        'key' => 'OTHER_TEAM_TOKEN',
        'value' => 'other-team-secret',
        'type' => 'team',
        'team_id' => $otherTeam->id,
    ]);
    SharedEnvironmentVariable::create([
        'key' => 'OTHER_PROJECT_TOKEN',
        'value' => 'other-project-secret',
        'type' => 'project',
        'project_id' => $otherProject->id,
        'team_id' => $this->team->id,
    ]);
    SharedEnvironmentVariable::create([
        'key' => 'OTHER_ENVIRONMENT_TOKEN',
        'value' => 'other-environment-secret',
        'type' => 'environment',
        'environment_id' => $otherEnvironment->id,
        'project_id' => $this->project->id,
        'team_id' => $this->team->id,
    ]);
    SharedEnvironmentVariable::create([
        'key' => 'OTHER_SERVER_TOKEN',
        'value' => 'other-server-secret',
        'type' => 'server',
        'server_id' => $otherServer->id,
        'team_id' => $this->team->id,
    ]);

    $component = Livewire::test(All::class, ['resource' => $application])
        ->set('parameters', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'application_uuid' => $application->uuid,
        ]);

    $availableSharedVariables = $component->get('availableSharedVariables');

    expect($availableSharedVariables['team'])
        ->toContain('TEAM_API_TOKEN')
        ->not->toContain('OTHER_TEAM_TOKEN');
    expect($availableSharedVariables['project'])
        ->toContain('PROJECT_API_TOKEN')
        ->not->toContain('OTHER_PROJECT_TOKEN');
    expect($availableSharedVariables['environment'])
        ->toContain('ENVIRONMENT_API_TOKEN')
        ->not->toContain('OTHER_ENVIRONMENT_TOKEN');
    expect($availableSharedVariables['server'])
        ->toContain('SERVER_API_TOKEN')
        ->not->toContain('OTHER_SERVER_TOKEN');

    $serverRouteSharedVariables = Livewire::test(All::class, ['resource' => $application])
        ->set('parameters', [
            'server_uuid' => $server->uuid,
        ])
        ->get('availableSharedVariables');

    expect($serverRouteSharedVariables['server'])
        ->toContain('SERVER_API_TOKEN')
        ->not->toContain('OTHER_SERVER_TOKEN');

    $serviceRouteSharedVariables = Livewire::test(All::class, ['resource' => $service])
        ->set('parameters', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'service_uuid' => $service->uuid,
        ])
        ->get('availableSharedVariables');

    expect($serviceRouteSharedVariables['server'])
        ->toContain('SERVER_API_TOKEN')
        ->not->toContain('OTHER_SERVER_TOKEN');
});

it('does not expose shared variables from cross-team route parameters', function () {
    session(['currentTeam' => $this->team]);

    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
    ]);

    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnvironment = Environment::factory()->create(['project_id' => $otherProject->id]);
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->first()
        ?? StandaloneDocker::factory()->create([
            'server_id' => $otherServer->id,
            'network' => 'coolify-test-'.$otherServer->id,
        ]);
    $otherApplication = Application::factory()->create([
        'environment_id' => $otherEnvironment->id,
        'destination_id' => $otherDestination->id,
        'destination_type' => $otherDestination->getMorphClass(),
    ]);
    $otherService = Service::factory()->create([
        'environment_id' => $otherEnvironment->id,
        'destination_id' => $otherDestination->id,
        'destination_type' => $otherDestination->getMorphClass(),
        'server_id' => $otherServer->id,
    ]);

    SharedEnvironmentVariable::create([
        'key' => 'OTHER_PROJECT_TOKEN',
        'value' => 'other-project-secret',
        'type' => 'project',
        'project_id' => $otherProject->id,
        'team_id' => $otherTeam->id,
    ]);
    SharedEnvironmentVariable::create([
        'key' => 'OTHER_ENVIRONMENT_TOKEN',
        'value' => 'other-environment-secret',
        'type' => 'environment',
        'environment_id' => $otherEnvironment->id,
        'project_id' => $otherProject->id,
        'team_id' => $otherTeam->id,
    ]);
    SharedEnvironmentVariable::create([
        'key' => 'OTHER_SERVER_TOKEN',
        'value' => 'other-server-secret',
        'type' => 'server',
        'server_id' => $otherServer->id,
        'team_id' => $otherTeam->id,
    ]);

    $applicationRouteSharedVariables = Livewire::test(All::class, ['resource' => $application])
        ->set('parameters', [
            'project_uuid' => $otherProject->uuid,
            'environment_uuid' => $otherEnvironment->uuid,
            'application_uuid' => $otherApplication->uuid,
        ])
        ->get('availableSharedVariables');

    expect($applicationRouteSharedVariables['project'])->not->toContain('OTHER_PROJECT_TOKEN');
    expect($applicationRouteSharedVariables['environment'])->not->toContain('OTHER_ENVIRONMENT_TOKEN');
    expect($applicationRouteSharedVariables['server'])->not->toContain('OTHER_SERVER_TOKEN');

    $serverRouteSharedVariables = Livewire::test(All::class, ['resource' => $application])
        ->set('parameters', [
            'server_uuid' => $otherServer->uuid,
        ])
        ->get('availableSharedVariables');

    expect($serverRouteSharedVariables['server'])->not->toContain('OTHER_SERVER_TOKEN');

    $serviceRouteSharedVariables = Livewire::test(All::class, ['resource' => $otherService])
        ->set('parameters', [
            'project_uuid' => $otherProject->uuid,
            'environment_uuid' => $otherEnvironment->uuid,
            'service_uuid' => $otherService->uuid,
        ])
        ->get('availableSharedVariables');

    expect($serviceRouteSharedVariables['project'])->not->toContain('OTHER_PROJECT_TOKEN');
    expect($serviceRouteSharedVariables['environment'])->not->toContain('OTHER_ENVIRONMENT_TOKEN');
    expect($serviceRouteSharedVariables['server'])->not->toContain('OTHER_SERVER_TOKEN');
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
        ->set('search', 'api');

    expect($component->instance()->environmentVariables->pluck('key')->all())
        ->toBe(['API_KEY']);
});

it('treats production environment variable search wildcards literally', function () {
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

    EnvironmentVariable::create([
        'key' => 'PERCENT%KEY',
        'value' => 'percent-secret',
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    $component = Livewire::test(All::class, ['resource' => $application])
        ->set('search', 'api_key');

    expect($component->instance()->environmentVariables->pluck('key')->all())
        ->toBe(['API_KEY']);

    $component->set('search', '%KEY');

    expect($component->instance()->environmentVariables->pluck('key')->all())
        ->toBe(['PERCENT%KEY']);
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
        ->set('search', 'api');

    expect($component->instance()->hardcodedEnvironmentVariables->pluck('key')->all())
        ->toBe(['API_TOKEN']);
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
        ->set('search', 'api')
        ->assertSee('Production Environment Variables')
        ->assertDontSee('No environment variables found.');

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
        ->set('search', 'api')
        ->assertSee('Production Environment Variables')
        ->assertDontSee('Preview Deployments Environment Variables')
        ->assertDontSee('PREVIEW_TOKEN');

    expect($component->instance()->environmentVariables->pluck('key')->all())
        ->toBe(['API_KEY']);
});
