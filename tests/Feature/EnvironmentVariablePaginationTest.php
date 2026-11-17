<?php

use App\Livewire\Project\Shared\EnvironmentVariable\All;
use App\Livewire\Project\Shared\EnvironmentVariable\Show;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
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

it('hides preview scope for non-git applications', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'build_pack' => 'dockerimage',
        'git_repository' => null,
    ]);

    Livewire::test(All::class, ['resource' => $application])
        ->assertSet('showPreview', false);
});

it('paginates managed environment variables without loading every row into the page collection', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'build_pack' => 'dockerfile',
    ]);

    // Remove factory defaults so counts stay predictable.
    EnvironmentVariable::query()
        ->where('resourceable_type', Application::class)
        ->where('resourceable_id', $application->id)
        ->delete();

    for ($i = 1; $i <= 25; $i++) {
        EnvironmentVariable::create([
            'key' => sprintf('VAR_%02d', $i),
            'value' => "value-{$i}",
            'order' => $i,
            'is_preview' => false,
            'resourceable_type' => Application::class,
            'resourceable_id' => $application->id,
        ]);
    }

    // Creating production vars auto-mirrors preview rows; keep this test production-only.
    EnvironmentVariable::query()
        ->where('resourceable_type', Application::class)
        ->where('resourceable_id', $application->id)
        ->where('is_preview', true)
        ->delete();

    $component = Livewire::test(All::class, ['resource' => $application->fresh()])
        ->call('loadEnvironmentVariables');

    expect($component->instance()->environmentVariableRowCount)->toBe(25)
        ->and($component->instance()->environmentVariableLastPage)->toBe(3)
        ->and($component->instance()->environmentVariablePageRows)->toHaveCount(10)
        ->and($component->instance()->environmentVariablePageRows->pluck('environmentVariable.key')->all())
        ->toBe([
            'VAR_01', 'VAR_02', 'VAR_03', 'VAR_04', 'VAR_05',
            'VAR_06', 'VAR_07', 'VAR_08', 'VAR_09', 'VAR_10',
        ]);

    $component->call('nextEnvironmentVariablePage');

    expect($component->get('page'))->toBe(2)
        ->and($component->instance()->environmentVariablePageRows)->toHaveCount(10)
        ->and($component->instance()->environmentVariablePageRows->pluck('environmentVariable.key')->all())
        ->toBe([
            'VAR_11', 'VAR_12', 'VAR_13', 'VAR_14', 'VAR_15',
            'VAR_16', 'VAR_17', 'VAR_18', 'VAR_19', 'VAR_20',
        ]);

    $component->call('setEnvironmentVariablePage', 3);

    expect($component->instance()->environmentVariablePageRows)->toHaveCount(5)
        ->and($component->instance()->environmentVariablePageRows->pluck('environmentVariable.key')->all())
        ->toBe(['VAR_21', 'VAR_22', 'VAR_23', 'VAR_24', 'VAR_25']);
});

it('paginates across production then preview segments', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'build_pack' => 'dockerfile',
    ]);

    EnvironmentVariable::query()
        ->where('resourceable_type', Application::class)
        ->where('resourceable_id', $application->id)
        ->delete();

    for ($i = 1; $i <= 8; $i++) {
        EnvironmentVariable::create([
            'key' => sprintf('PROD_%02d', $i),
            'value' => "prod-{$i}",
            'order' => $i,
            'is_preview' => false,
            'resourceable_type' => Application::class,
            'resourceable_id' => $application->id,
        ]);
    }

    // Creating production vars also auto-creates preview mirrors; replace with controlled preview set.
    EnvironmentVariable::query()
        ->where('resourceable_type', Application::class)
        ->where('resourceable_id', $application->id)
        ->where('is_preview', true)
        ->delete();

    for ($i = 1; $i <= 8; $i++) {
        EnvironmentVariable::create([
            'key' => sprintf('PREV_%02d', $i),
            'value' => "prev-{$i}",
            'order' => $i,
            'is_preview' => true,
            'resourceable_type' => Application::class,
            'resourceable_id' => $application->id,
        ]);
    }

    $component = Livewire::test(All::class, ['resource' => $application->fresh()])
        ->call('loadEnvironmentVariables');

    expect($component->instance()->environmentVariableRowCount)->toBe(16)
        ->and($component->instance()->environmentVariablePageRows->pluck('environmentVariable.key')->all())
        ->toBe([
            'PROD_01', 'PROD_02', 'PROD_03', 'PROD_04', 'PROD_05',
            'PROD_06', 'PROD_07', 'PROD_08', 'PREV_01', 'PREV_02',
        ]);

    $component->call('nextEnvironmentVariablePage');

    expect($component->instance()->environmentVariablePageRows->pluck('environmentVariable.key')->all())
        ->toBe([
            'PREV_03', 'PREV_04', 'PREV_05', 'PREV_06', 'PREV_07', 'PREV_08',
        ]);
});

it('counts with a lightweight query rather than hydrating every model for the page total', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'build_pack' => 'dockerfile',
    ]);

    EnvironmentVariable::query()
        ->where('resourceable_type', Application::class)
        ->where('resourceable_id', $application->id)
        ->delete();

    for ($i = 1; $i <= 15; $i++) {
        EnvironmentVariable::create([
            'key' => sprintf('COUNT_%02d', $i),
            'value' => "value-{$i}",
            'order' => $i,
            'is_preview' => false,
            'resourceable_type' => Application::class,
            'resourceable_id' => $application->id,
        ]);
    }

    // Drop auto-created preview mirrors so only production rows exist.
    EnvironmentVariable::query()
        ->where('resourceable_type', Application::class)
        ->where('resourceable_id', $application->id)
        ->where('is_preview', true)
        ->delete();

    $component = Livewire::test(All::class, ['resource' => $application->fresh()])
        ->call('loadEnvironmentVariables');

    DB::enableQueryLog();
    $count = $component->instance()->environmentVariableRowCount;
    $pageRows = $component->instance()->environmentVariablePageRows;
    $queries = collect(DB::getQueryLog());

    expect($count)->toBe(15)
        ->and($pageRows)->toHaveCount(10);

    $selectQueries = $queries->filter(fn (array $query) => str_contains(strtolower($query['query']), 'select'));
    // One COUNT for the production segment + one SELECT for the page slice.
    expect($selectQueries->count())->toBeLessThanOrEqual(4);
});

it('does not decrypt environment variable values until edit is opened', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'build_pack' => 'dockerfile',
    ]);

    EnvironmentVariable::query()
        ->where('resourceable_type', Application::class)
        ->where('resourceable_id', $application->id)
        ->delete();

    $env = EnvironmentVariable::create([
        'key' => 'SECRET_KEY',
        'value' => 'super-secret-value',
        'order' => 1,
        'is_preview' => false,
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    EnvironmentVariable::query()
        ->where('resourceable_type', Application::class)
        ->where('resourceable_id', $application->id)
        ->where('is_preview', true)
        ->delete();

    $component = Livewire::test(All::class, ['resource' => $application->fresh()])
        ->call('loadEnvironmentVariables');

    $pageEnv = $component->instance()->environmentVariablePageRows->first()['environmentVariable'];

    expect(array_key_exists('value', $pageEnv->getAttributes()))->toBeFalse()
        ->and($pageEnv->getAppends())->toBe([]);

    $show = Livewire::test(Show::class, [
        'env' => $pageEnv,
        'type' => 'application',
    ]);

    expect($show->get('valuesLoaded'))->toBeFalse()
        ->and($show->get('value'))->toBeNull()
        ->and($show->get('editorOpen'))->toBeFalse();

    $show->set('editorOpen', true)->call('loadValues');

    expect($show->get('valuesLoaded'))->toBeTrue()
        ->and($show->get('value'))->toBe('super-secret-value')
        ->and($show->get('editorOpen'))->toBeTrue();
});
