<?php

use App\Actions\Database\StartDragonfly;
use App\Actions\Database\StartKeydb;
use App\Actions\Database\StartMariadb;
use App\Actions\Database\StartMongodb;
use App\Actions\Database\StartMysql;
use App\Actions\Database\StartPostgresql;
use App\Actions\Database\StartRedis;
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
    config(['app.maintenance.store' => 'array', 'cache.default' => 'array', 'constants.ssh.mux_enabled' => false]);
    // The queued ServerStorageSaveJob does not run, as when it runs after the containers start.
    Queue::fake();

    // Every path on the server is missing, and every path is inside the resource directory.
    Process::fake(function ($process) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
        if (str_contains($command, 'empty-directory')) {
            return Process::result(output: collect(range(1, substr_count($command, '/ssl/server.')))->map(fn (int $index) => "{$index}:missing")->implode("\n"));
        }
        if (str_contains($command, 'readlink -f')) {
            return Process::result(output: 'OK');
        }

        return Process::result(output: str_contains($command, 'test -') ? 'NOK' : '');
    });

    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $this->server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
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

dataset('ssl-database-start-actions-with-certificate-files', [
    'postgresql' => ['create_standalone_postgresql', StartPostgresql::class, ['server.crt', 'server.key']],
    'mysql' => ['create_standalone_mysql', StartMysql::class, ['server.crt', 'server.key']],
    'mariadb' => ['create_standalone_mariadb', StartMariadb::class, ['server.crt', 'server.key']],
    'mongodb' => ['create_standalone_mongodb', StartMongodb::class, ['server.pem']],
    'redis' => ['create_standalone_redis', StartRedis::class, ['server.crt', 'server.key']],
    'keydb' => ['create_standalone_keydb', StartKeydb::class, ['server.crt', 'server.key']],
    'dragonfly' => ['create_standalone_dragonfly', StartDragonfly::class, ['server.crt', 'server.key']],
]);

it('writes a new SSL certificate file on the server before the container starts', function (string $createDatabase, string $startAction, array $files) {
    $database = $createDatabase($this->environment->id, $this->destination);
    $database->update(['enable_ssl' => true]);

    $startAction::run($database->fresh(), new Activity);

    foreach ($files as $file) {
        $path = escapeshellarg($database->workdir()."/ssl/{$file}");
        Process::assertRan(fn ($process) => str_contains($process->command, "base64 -d | tee {$path}"));
    }
    $commands = implode("\n", $this->executor->commands);
    expect($commands)->toContain('missing configuration file')
        ->and(strpos($commands, 'missing configuration file'))->toBeLessThan(strpos($commands, 'docker-compose.yml up -d'));
})->with('ssl-database-start-actions-with-certificate-files');

it('writes the MongoDB server.pem before the certificate owner change mounts it', function () {
    $database = create_standalone_mongodb($this->environment->id, $this->destination);
    $database->update(['enable_ssl' => true]);

    StartMongodb::run($database->fresh(), new Activity);

    $commands = implode("\n", $this->executor->commands);
    expect($commands)->toContain('Writing 1 missing configuration file.')
        ->and(strpos($commands, 'Writing 1 missing configuration file.'))->toBeLessThan(strpos($commands, '--entrypoint chown'));
});
