<?php

use App\Livewire\Project\Application\General as ApplicationGeneral;
use App\Livewire\Project\Database\Redis\General as RedisGeneral;
use App\Livewire\Project\Service\EditCompose;
use App\Livewire\Project\Service\StackForm;
use App\Livewire\Project\Shared\EnvironmentVariable\All;
use App\Livewire\Project\Shared\EnvironmentVariable\Show;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Exceptions\EventHandlerDoesNotExist;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config(['constants.ssh.mux_enabled' => false]);
    Server::flushIdentityMap();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
});

/**
 * A `refreshEnvs` dispatch without a target reaches every environment variable row
 * as well as the list, which races row removal ("Snapshot missing on Livewire component").
 */
function assertNoPageWideRefreshEnvs(Testable $component): void
{
    $pageWide = collect(data_get($component->effects, 'dispatches'))
        ->filter(fn (array $dispatch): bool => $dispatch['name'] === 'refreshEnvs'
            && ! isset($dispatch['to'])
            && empty($dispatch['self']));

    expect($pageWide)->toBeEmpty();
}

test('reloading the compose file refreshes only the environment variable list', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'git_repository' => 'https://github.com/coollabsio/compose-app',
        'git_branch' => 'main',
        'base_directory' => '/',
        'docker_compose_location' => '/docker-compose.yml',
    ]);
    Process::fake(function ($process) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
        if (str_contains($command, 'git --version')) {
            return Process::result(output: 'git version 2.43.0');
        }
        if (str_contains($command, 'head -c')) {
            return Process::result(output: "services:\n  web:\n    image: nginx:alpine\n");
        }

        return Process::result(output: '');
    });

    $component = Livewire::test(ApplicationGeneral::class, ['application' => $this->application->fresh()])
        ->call('loadComposeFile')
        ->assertDispatched('success', 'Docker compose file loaded.')
        ->assertDispatchedTo(All::class, 'refreshEnvs');

    assertNoPageWideRefreshEnvs($component);
});

test('saving redis credentials refreshes only the environment variable list', function () {
    $database = create_standalone_redis($this->environment->id, $this->destination, ['redis_password' => 'old-password'])->fresh();

    $component = Livewire::test(RedisGeneral::class, ['database' => $database])
        ->set('redisPassword', 'new-password')
        ->call('submit')
        ->assertDispatched('success', 'Database updated.')
        ->assertDispatchedTo(All::class, 'refreshEnvs');

    assertNoPageWideRefreshEnvs($component);
    expect($database->runtime_environment_variables()->where('key', 'REDIS_PASSWORD')->firstOrFail()->value)
        ->toBe('new-password');
});

test('saving a service refreshes the compose editor and the environment variable list only', function () {
    Process::fake();
    $service = Service::factory()->create([
        'name' => 'stack-service',
        'connect_to_docker_network' => false,
        'docker_compose_raw' => "services:\n  app:\n    image: nginx:alpine\n",
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'environment_id' => $this->environment->id,
    ]);

    $component = Livewire::test(StackForm::class, ['service' => $service])
        ->call('submit')
        ->assertDispatched('success', 'Service saved.')
        ->assertDispatchedTo(All::class, 'refreshEnvs')
        ->assertDispatchedTo(EditCompose::class, 'refreshEnvs');

    assertNoPageWideRefreshEnvs($component);
});

test('locking a variable updates the row itself and refreshes only the list', function () {
    $env = EnvironmentVariable::create([
        'key' => 'LOCK_ME',
        'value' => 'secret',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    $component = Livewire::test(Show::class, ['env' => $env, 'type' => 'application'])
        ->call('lock')
        ->assertSet('isLocked', true)
        ->assertSet('is_shown_once', true)
        ->assertSet('valuesLoaded', false)
        ->assertDispatchedTo(All::class, 'refreshEnvs');

    assertNoPageWideRefreshEnvs($component);
    expect($env->fresh()->is_shown_once)->toBeTruthy();
});

test('environment variable rows do not handle the list-wide refreshEnvs event', function () {
    $env = EnvironmentVariable::create([
        'key' => 'ROW_KEY',
        'value' => 'value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    $row = Livewire::test(Show::class, ['env' => $env, 'type' => 'application']);

    expect(fn () => $row->dispatch('refreshEnvs'))->toThrow(EventHandlerDoesNotExist::class);
});

test('refreshing the list renders added rows with current data and drops deleted rows', function () {
    $removed = EnvironmentVariable::create([
        'key' => 'REMOVED_KEY',
        'value' => 'value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    $component = Livewire::test(All::class, ['resource' => $this->application])
        ->call('loadEnvironmentVariables')
        ->assertSee('REMOVED_KEY');

    $removed->delete();
    EnvironmentVariable::create([
        'key' => 'ADDED_KEY',
        'value' => 'value',
        'comment' => 'fresh comment',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    $component->dispatch('refreshEnvs')
        ->assertSee('ADDED_KEY')
        ->assertSee('fresh comment')
        ->assertDontSee('REMOVED_KEY');
});
