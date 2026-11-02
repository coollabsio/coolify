<?php

use App\Livewire\Project\Shared\EnvironmentVariable\All;
use App\Livewire\SharedVariables\Team\Index;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\SharedEnvironmentVariable;
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
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create(['environment_id' => $this->environment->id]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

$values = [
    'QUOTED' => '"abc"',
    'SINGLE_QUOTED' => "'\$FOO'",
    'HASH' => 'value #not-a-comment',
    'BOTH_QUOTES' => '\'"Company :: App" <noreply@example.com>\'',
];

it('keeps values unchanged when the developer view is saved without edits', function () use ($values) {
    foreach ($values as $key => $value) {
        EnvironmentVariable::create([
            'key' => $key,
            'value' => $value,
            'is_literal' => true,
            'resourceable_type' => Application::class,
            'resourceable_id' => $this->application->id,
        ]);
    }

    $component = Livewire::test(All::class, ['resource' => $this->application])
        ->call('loadEnvironmentVariables')
        ->call('switch');

    $component->call('submit')->assertHasNoErrors();
    $component->call('submit')->assertHasNoErrors();

    expect($this->application->environment_variables()->whereIn('key', array_keys($values))->get()->pluck('value', 'key')->all())->toBe($values)
        ->and($this->application->environment_variables()->whereIn('key', array_keys($values))->whereNotNull('comment')->count())->toBe(0)
        ->and($component->get('variables'))->toContain("QUOTED='\"abc\"'")
        ->and($component->get('variables'))->toContain('BOTH_QUOTES=(Value cannot be shown in this view, edit in normal view)');
});

it('keeps shared variable values unchanged when the developer view is saved without edits', function () use ($values) {
    foreach ($values as $key => $value) {
        SharedEnvironmentVariable::create([
            'key' => $key,
            'value' => $value,
            'type' => 'team',
            'team_id' => $this->team->id,
        ]);
    }

    $component = Livewire::test(Index::class)->call('switch');

    $component->call('submit')->assertHasNoErrors();
    $component->call('submit')->assertHasNoErrors();

    expect($this->team->environment_variables()->get()->pluck('value', 'key')->all())->toBe($values);
});
