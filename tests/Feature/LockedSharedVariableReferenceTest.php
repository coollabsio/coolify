<?php

use App\Livewire\Project\Shared\EnvironmentVariable\Show;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Server::flushIdentityMap();

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

function createSharedVariableForLockTest(string $type, bool $locked): SharedEnvironmentVariable
{
    $owner = match ($type) {
        'team' => [],
        'project' => ['project_id' => test()->project->id],
        'environment' => ['project_id' => test()->project->id, 'environment_id' => test()->environment->id],
        'server' => ['server_id' => test()->server->id],
    };

    return SharedEnvironmentVariable::create([
        'key' => 'DB_SECRET',
        'value' => 'review-fix-e-shared-secret',
        'type' => $type,
        'team_id' => test()->team->id,
        'is_shown_once' => $locked,
        ...$owner,
    ]);
}

function createReferencingVariableForLockTest(string $value): EnvironmentVariable
{
    return EnvironmentVariable::create([
        'key' => 'DATABASE_PASSWORD',
        'value' => $value,
        'resourceable_type' => Application::class,
        'resourceable_id' => test()->application->id,
    ]);
}

test('copying a reference does not reveal a locked shared variable', function (string $type) {
    createSharedVariableForLockTest($type, locked: true);

    Livewire::test(Show::class, ['env' => createReferencingVariableForLockTest("{{{$type}.DB_SECRET}}"), 'type' => 'application'])
        ->call('copyValue')
        ->assertReturned("{{{$type}.DB_SECRET}}");
})->with(['team', 'project', 'environment', 'server']);

test('copying an embedded reference does not reveal a locked shared variable', function () {
    createSharedVariableForLockTest('team', locked: true);

    Livewire::test(Show::class, ['env' => createReferencingVariableForLockTest('postgres://app:{{team.DB_SECRET}}@db'), 'type' => 'application'])
        ->call('copyValue')
        ->assertReturned('postgres://app:{{team.DB_SECRET}}@db');
});

test('the resolved value in the editor does not reveal a locked shared variable', function (string $type) {
    createSharedVariableForLockTest($type, locked: true);

    $component = Livewire::test(Show::class, ['env' => createReferencingVariableForLockTest("{{{$type}.DB_SECRET}}"), 'type' => 'application'])
        ->call('loadValues');

    expect($component->get('real_value'))->not->toContain('review-fix-e-shared-secret')
        ->and(json_encode($component->snapshot))->not->toContain('review-fix-e-shared-secret');
})->with(['team', 'project', 'environment', 'server']);

test('the editor and copy still resolve an unlocked shared variable', function () {
    createSharedVariableForLockTest('team', locked: false);

    Livewire::test(Show::class, ['env' => createReferencingVariableForLockTest('{{team.DB_SECRET}}'), 'type' => 'application'])
        ->call('loadValues')
        ->assertSet('real_value', 'review-fix-e-shared-secret')
        ->call('copyValue')
        ->assertReturned('review-fix-e-shared-secret');
});

test('deployments still receive the value of a locked shared variable', function (string $type) {
    createSharedVariableForLockTest($type, locked: true);
    $env = createReferencingVariableForLockTest("{{{$type}.DB_SECRET}}");

    expect($env->getResolvedValueWithServer($this->server))->toBe('review-fix-e-shared-secret')
        ->and($env->get_real_environment_variables_with_server($env->value, $this->application, $this->server))->toBe('review-fix-e-shared-secret')
        ->and($env->fresh()->real_value)->toBe('review-fix-e-shared-secret');
})->with(['team', 'project', 'environment', 'server']);
