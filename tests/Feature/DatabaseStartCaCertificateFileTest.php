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
    config(['app.maintenance.store' => 'array', 'cache.default' => 'array']);
    Process::fake();
    Queue::fake();

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

dataset('ssl-database-start-actions', [
    'postgresql' => ['create_standalone_postgresql', StartPostgresql::class],
    'mysql' => ['create_standalone_mysql', StartMysql::class],
    'mariadb' => ['create_standalone_mariadb', StartMariadb::class],
    'mongodb' => ['create_standalone_mongodb', StartMongodb::class],
    'redis' => ['create_standalone_redis', StartRedis::class],
    'keydb' => ['create_standalone_keydb', StartKeydb::class],
    'dragonfly' => ['create_standalone_dragonfly', StartDragonfly::class],
]);

it('writes the CA that signs the database certificate to the shared CA file on start', function (string $createDatabase, string $startAction) {
    $this->server->generateCaCertificate();
    $caCertificate = $this->server->sslCertificates()->where('is_ca_certificate', true)->firstOrFail();

    $database = $createDatabase($this->environment->id, $this->destination);
    $database->update(['enable_ssl' => true]);

    $startAction::run($database->fresh(), new Activity);

    $databaseCertificate = $database->sslCertificates()->firstOrFail()->ssl_certificate;

    expect(openssl_x509_verify($databaseCertificate, openssl_pkey_get_public($caCertificate->ssl_certificate)))->toBe(1)
        ->and(implode("\n", $this->executor->commands))
        ->toContain("echo '".base64_encode($caCertificate->ssl_certificate)."' | base64 -d | tee ");
})->with('ssl-database-start-actions');
