<?php

use App\Actions\Database\StartRedis;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use App\Services\DatabaseStartCommandExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config(['app.maintenance.store' => 'array', 'cache.default' => 'array']);
    Process::fake();
    Queue::fake();
    Server::flushIdentityMap();

    $this->team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);

    $user = User::factory()->create();
    $this->team->members()->attach($user->id, ['role' => 'owner']);
    // Audit logging runs only for an authenticated user, and it loads the database while REDIS_PASSWORD is created.
    $this->actingAs($user);

    $this->executor = new class
    {
        public array $commands = [];

        public function execute(array $commands, $database, Activity $activity): Activity
        {
            $this->commands = $commands;

            return $activity;
        }
    };
    app()->instance(DatabaseStartCommandExecutor::class, $this->executor);
});

it('creates one REDIS_USERNAME variable and starts with unique environment entries', function () {
    $database = create_standalone_redis($this->environment->id, $this->destination, ['image' => 'redis:7.2']);

    expect($database->runtime_environment_variables()->where('key', 'REDIS_USERNAME')->count())->toBe(1);

    StartRedis::run($database->fresh(), new Activity);

    $environment = null;
    foreach ($this->executor->commands as $command) {
        if (preg_match("#^echo '([^']+)' \| base64 -d \| tee \S+/docker-compose\.yml#", $command, $matches)) {
            $environment = Yaml::parse(base64_decode($matches[1]))['services'][$database->uuid]['environment'];
        }
    }

    expect($environment)->toHaveCount(2)
        ->and($environment)->toContain('REDIS_USERNAME=default')
        ->and(array_unique($environment))->toBe($environment);
});

it('removes duplicate REDIS_USERNAME variables and keeps the most recently changed one', function () {
    $database = create_standalone_redis($this->environment->id, $this->destination);
    $other = create_standalone_redis($this->environment->id, $this->destination);
    $kept = EnvironmentVariable::query()->create([
        'key' => 'REDIS_USERNAME',
        'value' => 'admin',
        'resourceable_type' => StandaloneRedis::class,
        'resourceable_id' => $database->id,
    ]);
    $kept->forceFill(['updated_at' => now()->addMinute()])->saveQuietly();

    $migration = require database_path('migrations/2026_10_09_115444_remove_duplicate_redis_username_environment_variables.php');
    $migration->up();

    expect($database->runtime_environment_variables()->where('key', 'REDIS_USERNAME')->pluck('id')->all())->toBe([$kept->id])
        ->and($database->runtime_environment_variables()->where('key', 'REDIS_PASSWORD')->count())->toBe(1)
        ->and($other->runtime_environment_variables()->where('key', 'REDIS_USERNAME')->count())->toBe(1);
});
