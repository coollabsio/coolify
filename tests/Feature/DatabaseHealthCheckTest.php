<?php

use App\Livewire\Project\Database\Health;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('defaults to an enabled healthcheck when nothing is configured', function () {
    $database = new StandalonePostgresql;

    expect($database->isHealthcheckEnabled())->toBeTrue();
});

it('builds the compose healthcheck block from the model timing fields', function () {
    $database = new StandalonePostgresql([
        'health_check_interval' => 30,
        'health_check_timeout' => 7,
        'health_check_retries' => 4,
        'health_check_start_period' => 12,
    ]);

    $config = $database->healthCheckConfiguration(['CMD', 'pg_isready']);

    expect($config)->toBe([
        'test' => ['CMD', 'pg_isready'],
        'interval' => '30s',
        'timeout' => '7s',
        'retries' => 4,
        'start_period' => '12s',
    ]);
});

it('falls back to safe defaults when timing fields are missing', function () {
    $database = new StandalonePostgresql;

    $config = $database->healthCheckConfiguration(['CMD', 'pg_isready']);

    expect($config['interval'])->toBe('15s')
        ->and($config['timeout'])->toBe('5s')
        ->and($config['retries'])->toBe(5)
        ->and($config['start_period'])->toBe('5s');
});

it('reports the healthcheck as disabled when the flag is false', function () {
    $database = new StandalonePostgresql(['health_check_enabled' => false]);

    expect($database->isHealthcheckEnabled())->toBeFalse();
});

it('uses distinct hash fragments for ambiguous healthcheck values', function () {
    $enabledDatabase = new StandalonePostgresql([
        'health_check_enabled' => true,
        'health_check_interval' => 5,
        'health_check_timeout' => 5,
        'health_check_retries' => 5,
        'health_check_start_period' => 5,
    ]);

    $disabledDatabase = new StandalonePostgresql([
        'health_check_enabled' => false,
        'health_check_interval' => 15,
        'health_check_timeout' => 5,
        'health_check_retries' => 5,
        'health_check_start_period' => 5,
    ]);

    $getHashFragment = function () {
        return $this->healthCheckConfigurationHash();
    };

    expect($getHashFragment->call($enabledDatabase))
        ->toBe('1|5|5|5|5')
        ->not->toBe($getHashFragment->call($disabledDatabase))
        ->and($getHashFragment->call($disabledDatabase))->toBe('0|15|5|5|5');
});

it('does not mark configuration changed when health update authorization fails', function () {
    $database = new class
    {
        public ?string $config_hash = null;

        public int $configurationChangedChecks = 0;

        public function isConfigurationChanged(bool $save = false): bool
        {
            $this->configurationChangedChecks++;

            return true;
        }
    };

    $component = new class extends Health
    {
        public array $dispatchedEvents = [];

        public function authorize($ability, $arguments = [])
        {
            throw new AuthorizationException('This action is unauthorized.');
        }

        public function dispatch($event, ...$params)
        {
            $this->dispatchedEvents[] = $event;

            return null;
        }
    };

    $component->database = $database;
    $component->submit();

    expect($database->configurationChangedChecks)->toBe(0)
        ->and($component->dispatchedEvents)->toBe(['error']);
});

it('toggles database healthcheck and marks configuration changed', function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $database = StandalonePostgresql::create([
        'name' => 'db',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'db',
        'image' => 'postgres:17',
        'health_check_enabled' => false,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $database->config_hash = 'existing';
    $database->saveQuietly();
    $database->refresh();

    Livewire::test(Health::class, ['database' => $database])
        ->assertSet('healthCheckEnabled', false)
        ->call('toggleHealthcheck')
        ->assertSet('healthCheckEnabled', true)
        ->assertDispatched('success')
        ->assertDispatched('configurationChanged');

    expect($database->fresh()->health_check_enabled)->toBeTrue();
});
