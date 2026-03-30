<?php

use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = $this->user->teams()->first();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('SHARED_VARIABLE_TYPES includes server', function () {
    expect(SHARED_VARIABLE_TYPES)->toContain('server');
});

test('server environment_variables relationship returns server-scoped shared variables', function () {
    SharedEnvironmentVariable::create([
        'key' => 'SERVER_VAR',
        'value' => 'server_value',
        'type' => 'server',
        'server_id' => $this->server->id,
        'team_id' => $this->team->id,
    ]);

    $vars = $this->server->environment_variables;
    expect($vars)->toHaveCount(1)
        ->and($vars->first()->key)->toBe('SERVER_VAR')
        ->and($vars->first()->value)->toBe('server_value');
});

test('shared environment variable belongs to server', function () {
    $var = SharedEnvironmentVariable::create([
        'key' => 'MY_KEY',
        'value' => 'my_value',
        'type' => 'server',
        'server_id' => $this->server->id,
        'team_id' => $this->team->id,
    ]);

    expect($var->server->id)->toBe($this->server->id);
});

test('server shared variable dev view creates new variables', function () {
    Livewire\Livewire::test(\App\Livewire\SharedVariables\Server\Show::class)
        ->set('variables', 'SERVER_KEY=server_val')
        ->call('submit')
        ->assertHasNoErrors();

    $vars = $this->server->environment_variables()->pluck('value', 'key')->toArray();
    expect($vars)->toHaveKey('SERVER_KEY')
        ->and($vars['SERVER_KEY'])->toBe('server_val');
});

test('server shared variable dev view updates existing variable', function () {
    SharedEnvironmentVariable::create([
        'key' => 'EXISTING_VAR',
        'value' => 'old_value',
        'type' => 'server',
        'server_id' => $this->server->id,
        'team_id' => $this->team->id,
    ]);

    Livewire\Livewire::test(\App\Livewire\SharedVariables\Server\Show::class)
        ->set('variables', 'EXISTING_VAR=new_value')
        ->call('submit')
        ->assertHasNoErrors();

    $var = $this->server->environment_variables()->where('key', 'EXISTING_VAR')->first();
    expect($var->value)->toBe('new_value');
});

test('server shared variable dev view deletes removed variables', function () {
    SharedEnvironmentVariable::create([
        'key' => 'VAR_TO_KEEP',
        'value' => 'keep',
        'type' => 'server',
        'server_id' => $this->server->id,
        'team_id' => $this->team->id,
    ]);
    SharedEnvironmentVariable::create([
        'key' => 'VAR_TO_DELETE',
        'value' => 'delete',
        'type' => 'server',
        'server_id' => $this->server->id,
        'team_id' => $this->team->id,
    ]);

    Livewire\Livewire::test(\App\Livewire\SharedVariables\Server\Show::class)
        ->set('variables', 'VAR_TO_KEEP=keep')
        ->call('submit')
        ->assertHasNoErrors();

    expect($this->server->environment_variables()->where('key', 'VAR_TO_DELETE')->exists())->toBeFalse()
        ->and($this->server->environment_variables()->where('key', 'VAR_TO_KEEP')->exists())->toBeTrue();
});
