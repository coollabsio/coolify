<?php

use App\Models\Environment;
use App\Models\Project;
use App\Models\SharedEnvironmentVariable;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'admin']);

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create([
        'project_id' => $this->project->id,
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('environment shared variable dev view saves without openssl_encrypt error', function () {
    Livewire::test(\App\Livewire\SharedVariables\Environment\Show::class)
        ->set('variables', "MY_VAR=my_value\nANOTHER_VAR=another_value")
        ->call('submit')
        ->assertHasNoErrors();

    $vars = $this->environment->environment_variables()->pluck('value', 'key')->toArray();
    expect($vars)->toHaveKey('MY_VAR')
        ->and($vars['MY_VAR'])->toBe('my_value')
        ->and($vars)->toHaveKey('ANOTHER_VAR')
        ->and($vars['ANOTHER_VAR'])->toBe('another_value');
});

test('project shared variable dev view saves without openssl_encrypt error', function () {
    Livewire::test(\App\Livewire\SharedVariables\Project\Show::class)
        ->set('variables', 'PROJ_VAR=proj_value')
        ->call('submit')
        ->assertHasNoErrors();

    $vars = $this->project->environment_variables()->pluck('value', 'key')->toArray();
    expect($vars)->toHaveKey('PROJ_VAR')
        ->and($vars['PROJ_VAR'])->toBe('proj_value');
});

test('team shared variable dev view saves without openssl_encrypt error', function () {
    Livewire::test(\App\Livewire\SharedVariables\Team\Index::class)
        ->set('variables', 'TEAM_VAR=team_value')
        ->call('submit')
        ->assertHasNoErrors();

    $vars = $this->team->environment_variables()->pluck('value', 'key')->toArray();
    expect($vars)->toHaveKey('TEAM_VAR')
        ->and($vars['TEAM_VAR'])->toBe('team_value');
});

test('environment shared variable dev view updates existing variable', function () {
    SharedEnvironmentVariable::create([
        'key' => 'EXISTING_VAR',
        'value' => 'old_value',
        'type' => 'environment',
        'environment_id' => $this->environment->id,
        'project_id' => $this->project->id,
        'team_id' => $this->team->id,
    ]);

    Livewire::test(\App\Livewire\SharedVariables\Environment\Show::class)
        ->set('variables', 'EXISTING_VAR=new_value')
        ->call('submit')
        ->assertHasNoErrors();

    $var = $this->environment->environment_variables()->where('key', 'EXISTING_VAR')->first();
    expect($var->value)->toBe('new_value');
});
