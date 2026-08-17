<?php

use App\Livewire\SharedVariables\Environment\Show;
use App\Livewire\SharedVariables\Team\Index;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
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
    InstanceSettings::unguarded(function () {
        InstanceSettings::updateOrCreate([
            'id' => 0,
        ], [
            'is_registration_enabled' => true,
            'is_api_enabled' => true,
            'smtp_enabled' => true,
            'smtp_host' => 'localhost',
            'smtp_port' => 1025,
            'smtp_from_address' => 'hi@example.com',
            'smtp_from_name' => 'Coolify',
        ]);
    });

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

afterEach(function () {
    request()->setRouteResolver(function () {
        return null;
    });
});

test('environment shared variable dev view saves without openssl_encrypt error', function () {
    Livewire::test(Show::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
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
    Livewire::test(App\Livewire\SharedVariables\Project\Show::class, [
        'project_uuid' => $this->project->uuid,
    ])
        ->set('variables', 'PROJ_VAR=proj_value')
        ->call('submit')
        ->assertHasNoErrors();

    $vars = $this->project->environment_variables()->pluck('value', 'key')->toArray();
    expect($vars)->toHaveKey('PROJ_VAR')
        ->and($vars['PROJ_VAR'])->toBe('proj_value');
});

test('team shared variable dev view saves without openssl_encrypt error', function () {
    Livewire::test(Index::class)
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

    Livewire::test(Show::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->set('variables', 'EXISTING_VAR=new_value')
        ->call('submit')
        ->assertHasNoErrors();

    $var = $this->environment->environment_variables()->where('key', 'EXISTING_VAR')->first();
    expect($var->value)->toBe('new_value');
});

test('server shared variable dev view saves without openssl_encrypt error', function () {
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);

    Livewire::test(App\Livewire\SharedVariables\Server\Show::class, [
        'server_uuid' => $this->server->uuid,
    ])
        ->set('variables', "SERVER_VAR=server_value\nSECOND_SERVER_VAR=second_value")
        ->call('submit')
        ->assertHasNoErrors();

    $vars = $this->server->environment_variables()->pluck('value', 'key')->toArray();

    expect($vars)->toHaveKey('SERVER_VAR')
        ->and($vars['SERVER_VAR'])->toBe('server_value')
        ->and($vars)->toHaveKey('SECOND_SERVER_VAR')
        ->and($vars['SECOND_SERVER_VAR'])->toBe('second_value');
});

test('server shared variable dev view preserves inline comments', function () {
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);

    Livewire::test(App\Livewire\SharedVariables\Server\Show::class, [
        'server_uuid' => $this->server->uuid,
    ])
        ->set('variables', 'COMMENTED_SERVER_VAR=value # note from dev view')
        ->call('submit')
        ->assertHasNoErrors();

    $var = $this->server->environment_variables()->where('key', 'COMMENTED_SERVER_VAR')->first();

    expect($var)->not->toBeNull()
        ->and($var->value)->toBe('value')
        ->and($var->comment)->toBe('note from dev view');
});

test('server shared variable dev view updates existing variable', function () {
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);

    SharedEnvironmentVariable::create([
        'key' => 'EXISTING_SERVER_VAR',
        'value' => 'old_value',
        'comment' => 'old comment',
        'type' => 'server',
        'server_id' => $this->server->id,
        'team_id' => $this->team->id,
    ]);

    Livewire::test(App\Livewire\SharedVariables\Server\Show::class, [
        'server_uuid' => $this->server->uuid,
    ])
        ->set('variables', 'EXISTING_SERVER_VAR=new_value # updated comment')
        ->call('submit')
        ->assertHasNoErrors();

    $var = $this->server->environment_variables()->where('key', 'EXISTING_SERVER_VAR')->first();

    expect($var->value)->toBe('new_value')
        ->and($var->comment)->toBe('updated comment');
});
