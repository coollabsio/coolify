<?php

use App\Livewire\Project\Shared\EnvironmentVariable\All;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
    ]);

    $this->actingAs($this->user);
});

it('has a unique index on resourceable, key and is_preview', function () {
    expect(Schema::hasIndex('environment_variables', 'env_vars_resourceable_key_preview_unique'))->toBeTrue();
});

it('prevents inserting a second row with the same key at the database level', function () {
    EnvironmentVariable::create([
        'key' => 'API_KEY',
        'value' => 'secret',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
        'is_preview' => false,
    ]);

    expect(fn () => EnvironmentVariable::create([
        'key' => 'API_KEY',
        'value' => 'other-secret',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
        'is_preview' => false,
    ]))->toThrow(UniqueConstraintViolationException::class);

    expect(EnvironmentVariable::where('resourceable_type', Application::class)
        ->where('resourceable_id', $this->application->id)
        ->where('key', 'API_KEY')
        ->where('is_preview', false)
        ->count())->toBe(1);
});

it('does not create duplicate rows when the developer view is submitted twice', function () {
    $component = Livewire::test(All::class, ['resource' => $this->application])
        ->set('variables', "FIRST_KEY=first\nSECOND_KEY=second")
        ->call('submit')
        ->call('submit');

    $component->assertHasNoErrors();

    expect($this->application->environment_variables()->where('key', 'FIRST_KEY')->count())->toBe(1);
    expect($this->application->environment_variables()->where('key', 'SECOND_KEY')->count())->toBe(1);
});

it('keeps a single row per key when the same pasted text is saved repeatedly with changed values', function () {
    Livewire::test(All::class, ['resource' => $this->application])
        ->set('variables', 'PASTED_KEY=one')
        ->call('submit')
        ->set('variables', 'PASTED_KEY=two')
        ->call('submit');

    $rows = $this->application->environment_variables()->where('key', 'PASTED_KEY')->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->value)->toBe('two');
});

it('rejects adding a key that already exists via the add dialog', function () {
    EnvironmentVariable::create([
        'key' => 'EXISTING_KEY',
        'value' => 'original',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
        'is_preview' => false,
    ]);

    Livewire::test(All::class, ['resource' => $this->application])
        ->call('submit', ['key' => 'EXISTING_KEY', 'value' => 'other'])
        ->assertDispatched('error', function ($event, $params) {
            return in_array('Environment variable already exists.', $params);
        });

    $rows = $this->application->environment_variables()->where('key', 'EXISTING_KEY')->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->value)->toBe('original');
});
