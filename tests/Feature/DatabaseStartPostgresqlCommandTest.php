<?php

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
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config(['app.maintenance.store' => 'array', 'cache.default' => 'array']);
    Process::fake();
    Queue::fake();

    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $this->server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $this->server->generateCaCertificate();
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

    $this->startedCommand = function (array $attributes, string $image): ?array {
        $database = create_standalone_postgresql($this->environment->id, $this->destination, databaseImage: $image);
        $database->update($attributes);

        StartPostgresql::run($database->fresh(), new Activity);

        $composeCommand = collect($this->executor->commands)->first(fn (string $command) => str_ends_with($command, 'docker-compose.yml > /dev/null'));
        preg_match("/^echo '([^']+)'/", $composeCommand, $matches);
        $compose = Yaml::parse(base64_decode($matches[1]));

        return data_get($compose, "services.{$database->uuid}.command");
    };
});

it('keeps the Supabase config directory when SSL is enabled', function () {
    expect(($this->startedCommand)(['enable_ssl' => true], 'supabase/postgres:17.11.0.018'))->toBe([
        'postgres', '-D', '/etc/postgresql',
        '-c', 'ssl=on',
        '-c', 'ssl_cert_file=/var/lib/postgresql/certs/server.crt',
        '-c', 'ssl_key_file=/var/lib/postgresql/certs/server.key',
    ]);
});

it('keeps the image default command of Supabase without SSL', function () {
    expect(($this->startedCommand)(['enable_ssl' => false], 'supabase/postgres:17.11.0.018'))->toBeNull();
});

it('uses the custom config instead of the Supabase config directory', function () {
    expect(($this->startedCommand)(['enable_ssl' => true, 'postgres_conf' => "listen_addresses = '*'"], 'supabase/postgres:17.11.0.018'))->toBe([
        'postgres',
        '-c', 'config_file=/etc/postgresql/postgresql.conf',
        '-c', 'ssl=on',
        '-c', 'ssl_cert_file=/var/lib/postgresql/certs/server.crt',
        '-c', 'ssl_key_file=/var/lib/postgresql/certs/server.key',
    ]);
});

it('does not add a config directory for the official PostgreSQL image', function () {
    expect(($this->startedCommand)(['enable_ssl' => true], 'postgres:18-alpine'))->toBe([
        'postgres',
        '-c', 'ssl=on',
        '-c', 'ssl_cert_file=/var/lib/postgresql/certs/server.crt',
        '-c', 'ssl_key_file=/var/lib/postgresql/certs/server.key',
    ]);
});

it('starts a new Supabase database as supabase_admin and checks its health over 127.0.0.1', function () {
    $database = create_standalone_postgresql($this->environment->id, $this->destination, databaseImage: 'supabase/postgres:17.11.0.018')->fresh();

    StartPostgresql::run($database, new Activity);

    $composeCommand = collect($this->executor->commands)->first(fn (string $command) => str_ends_with($command, 'docker-compose.yml > /dev/null'));
    preg_match("/^echo '([^']+)'/", $composeCommand, $matches);
    $service = data_get(Yaml::parse(base64_decode($matches[1])), "services.{$database->uuid}");

    expect($database->postgres_user)->toBe('supabase_admin')
        ->and($service['environment'])->toContain('POSTGRES_USER=supabase_admin')
        ->and($service['healthcheck']['test'])->toBe(['CMD', 'psql', '-h', '127.0.0.1', '-U', 'supabase_admin', '-d', 'postgres', '-c', 'SELECT 1']);
});

it('keeps the chosen user of a new Supabase database and the postgres user of other images', function () {
    $custom = create_standalone_postgresql($this->environment->id, $this->destination, ['image' => 'supabase/postgres:17.11.0.018', 'postgres_user' => 'app']);
    $official = create_standalone_postgresql($this->environment->id, $this->destination);

    expect($custom->postgres_user)->toBe('app')
        ->and($official->fresh()->postgres_user)->toBe('postgres');
});
