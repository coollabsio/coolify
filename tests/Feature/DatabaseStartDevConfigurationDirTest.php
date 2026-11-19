<?php

use App\Actions\Database\StartMongodb;
use App\Actions\Database\StartPostgresql;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\DatabaseStartCommandExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config(['app.env' => 'local', 'app.maintenance.store' => 'array', 'cache.default' => 'array']);
    Process::fake();
    Queue::fake();

    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);

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

test('databases with SSL use their normal configuration folder in development', function (string $type) {
    $database = $type === 'postgresql'
        ? create_standalone_postgresql($this->environment->id, $this->destination)
        : create_standalone_mongodb($this->environment->id, $this->destination);
    $database->update(['enable_ssl' => true]);

    ($type === 'postgresql' ? StartPostgresql::class : StartMongodb::class)::run($database->fresh(), new Activity);

    $certificateFiles = $database->fileStorages()->pluck('fs_path');

    expect(isDev())->toBeTrue()
        ->and(implode("\n", $this->executor->commands))
        ->toContain('mkdir -p '.$database->workdir())
        ->not->toContain('coolify_dev_coolify_data')
        ->and($certificateFiles)->not->toBeEmpty()
        ->each->toStartWith($database->workdir().'/ssl/');
    // The file-mount path check accepts every certificate file.
    $certificateFiles->each(fn (string $path) => confinePathToBase($database->workdir(), $path, 'storage path'));
})->with(['postgresql', 'mongodb']);
