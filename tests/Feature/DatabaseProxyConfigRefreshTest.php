<?php

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Docker\GetContainersStatus;
use App\Jobs\PushServerUpdateJob;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Notifications\Container\ContainerRestarted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Cache::flush();
    Queue::fake();
    Notification::fake();

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $destination = $this->server->standaloneDockers()->firstOrFail();
    $this->database = StandalonePostgresql::create([
        'name' => 'public-db',
        'postgres_user' => 'postgres',
        'postgres_password' => encrypt('password'),
        'postgres_db' => 'app',
        'image' => 'postgres:16-alpine',
        'is_public' => true,
        'public_port' => 54321,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
});

function sentinelPushWithProxy(Server $server, StandalonePostgresql $database, array $proxyLabels): void
{
    (new PushServerUpdateJob($server, [
        'containers' => [
            [
                'name' => $database->uuid,
                'state' => 'running',
                'health_status' => 'healthy',
                'labels' => ['coolify.managed' => true, 'com.docker.compose.service' => $database->uuid],
            ],
            [
                'name' => "{$database->uuid}-proxy",
                'state' => 'running',
                'health_status' => 'healthy',
                'labels' => ['com.docker.compose.service' => "{$database->uuid}-proxy", ...$proxyLabels],
            ],
        ],
        'filesystem_usage_root' => ['used_percentage' => 10],
    ]))->handle();
}

function statusCheckWithProxy(Server $server, StandalonePostgresql $database, array $proxyLabels): void
{
    GetContainersStatus::run($server->fresh(), collect([
        [
            'Name' => '/'.$database->uuid,
            'State' => ['Status' => 'running', 'Health' => ['Status' => 'healthy']],
            'RestartCount' => 0,
            'Config' => ['Labels' => [
                'coolify.managed' => 'true',
                'coolify.type' => 'database',
                'com.docker.compose.service' => $database->uuid,
            ]],
        ],
        [
            'Name' => "/{$database->uuid}-proxy",
            'State' => ['Status' => 'running', 'Health' => ['Status' => 'healthy']],
            'RestartCount' => 0,
            'Config' => ['Labels' => ['com.docker.compose.service' => "{$database->uuid}-proxy", ...$proxyLabels]],
        ],
    ]), collect());
}

it('recreates a running TCP proxy that an older Coolify version started, once', function () {
    sentinelPushWithProxy($this->server, $this->database, []);
    sentinelPushWithProxy($this->server, $this->database, []);

    StartDatabaseProxy::assertPushed(1);
    Notification::assertNotSentTo($this->team, ContainerRestarted::class);
});

it('keeps a running TCP proxy that has the current configuration', function () {
    sentinelPushWithProxy($this->server, $this->database, [
        StartDatabaseProxy::CONFIG_VERSION_LABEL => StartDatabaseProxy::CONFIG_VERSION,
    ]);

    StartDatabaseProxy::assertNotPushed();
});

it('recreates an outdated TCP proxy in the SSH status check', function () {
    StartDatabaseProxy::shouldRun()->once();

    statusCheckWithProxy($this->server, $this->database, []);
    statusCheckWithProxy($this->server, $this->database, []);
});

it('keeps a current TCP proxy in the SSH status check', function () {
    StartDatabaseProxy::shouldRun()->never();

    statusCheckWithProxy($this->server, $this->database, [
        StartDatabaseProxy::CONFIG_VERSION_LABEL => StartDatabaseProxy::CONFIG_VERSION,
    ]);
});

it('labels a new TCP proxy with the current configuration and removes the old one only after the pull', function () {
    $scripts = [];
    Process::fake(function ($process) use (&$scripts) {
        $script = remoteProcessScript($process);
        $scripts[] = is_array($script) ? implode("\n", $script) : $script;

        return Process::result(output: '');
    });

    StartDatabaseProxy::run($this->database->fresh());

    $script = collect($scripts)->first(fn (string $script) => str_contains($script, 'docker-compose.yaml pull'));
    preg_match("/echo '([A-Za-z0-9+\/=]+)' \| base64 -d \| tee [^ ]+docker-compose\.yaml/", $script, $matches);
    $compose = Yaml::parse(base64_decode($matches[1]));
    $pull = strpos($script, 'docker-compose.yaml pull');
    $remove = strpos($script, dockerRemoveCommand("{$this->database->uuid}-proxy"));
    $up = strpos($script, 'docker-compose.yaml up -d');

    expect($compose['services']["{$this->database->uuid}-proxy"]['labels'])
        ->toBe([StartDatabaseProxy::CONFIG_VERSION_LABEL => StartDatabaseProxy::CONFIG_VERSION])
        ->and($remove)->toBeGreaterThan($pull)
        ->and($up)->toBeGreaterThan($remove);
});
