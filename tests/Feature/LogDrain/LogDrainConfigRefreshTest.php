<?php

use App\Actions\Server\StartLogDrain;
use App\Enums\LogDrainType;
use App\Jobs\PushServerUpdateJob;
use App\Jobs\ServerCheckJob;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Cache::flush();
    Notification::fake();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'log-drain-server',
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'logdrain_type' => LogDrainType::Axiom,
        'logdrain_config' => ['axiom' => ['dataset_name' => 'dataset', 'api_key' => 'key']],
    ]);
});

function sentinelPushWithLogDrain(Server $server, array $labels): void
{
    (new PushServerUpdateJob($server->fresh(), [
        'containers' => [
            ['name' => 'coolify-log-drain', 'state' => 'running', 'health_status' => 'unknown', 'labels' => ['coolify.managed' => 'true', ...$labels]],
        ],
        'filesystem_usage_root' => ['used_percentage' => 10],
    ]))->handle();
}

function serverCheckWithLogDrain(Server $server, array $labels): void
{
    $job = new ServerCheckJob($server->fresh());
    $job->containers = collect([
        ['Name' => '/coolify-log-drain', 'State' => ['Status' => 'running'], 'Config' => ['Labels' => ['coolify.managed' => 'true', ...$labels]]],
    ]);
    (fn () => $this->checkLogDrainContainer())->call($job);
}

/**
 * @return Collection<int, string>
 */
function startLogDrainScripts(Server $server): Collection
{
    $scripts = new Collection;
    Process::fake(function ($process) use ($scripts) {
        $scripts->push(remoteProcessScript($process));

        return Process::result();
    });

    StartLogDrain::run($server->fresh());

    return $scripts;
}

function generatedLogDrainFile(Collection $scripts, string $file): string
{
    preg_match("/echo '([A-Za-z0-9+\\/=]*)' \\| base64 -d \\| tee \\S+\\/".preg_quote($file, '/').' /', $scripts->implode("\n"), $matches);

    return base64_decode($matches[1]);
}

it('restarts a log drain that an older Coolify version started, once', function () {
    Queue::fake();

    sentinelPushWithLogDrain($this->server, []);
    sentinelPushWithLogDrain($this->server, [StartLogDrain::CONFIG_VERSION_LABEL => '0']);

    StartLogDrain::assertPushed(1);
});

it('keeps a log drain that has the current configuration', function () {
    Queue::fake();

    sentinelPushWithLogDrain($this->server, [StartLogDrain::CONFIG_VERSION_LABEL => StartLogDrain::CONFIG_VERSION]);

    StartLogDrain::assertNotPushed();
});

it('restarts an outdated log drain in the SSH server check, once', function () {
    Queue::fake();

    serverCheckWithLogDrain($this->server, []);
    serverCheckWithLogDrain($this->server, []);

    StartLogDrain::assertPushed(1);
});

it('keeps a current log drain in the SSH server check', function () {
    Queue::fake();

    serverCheckWithLogDrain($this->server, [StartLogDrain::CONFIG_VERSION_LABEL => StartLogDrain::CONFIG_VERSION]);

    StartLogDrain::assertNotPushed();
});

it('labels the log drain container with the current configuration version', function () {
    $compose = Yaml::parse(generatedLogDrainFile(startLogDrainScripts($this->server), 'docker-compose.yml'));

    expect($compose['services']['coolify-log-drain']['labels'])
        ->toContain(StartLogDrain::CONFIG_VERSION_LABEL.'='.StartLogDrain::CONFIG_VERSION);
});

it('pulls the new image before it removes the running log drain', function () {
    $script = startLogDrainScripts($this->server)->implode("\n");

    $pull = strpos($script, 'docker-compose.yml pull');
    $remove = strpos($script, 'docker rm -f coolify-log-drain');
    $start = strpos($script, 'docker-compose.yml up -d');

    expect($pull)->toBeInt()
        ->and($remove)->toBeGreaterThan($pull)
        ->and($start)->toBeGreaterThan($remove);
});

it('keeps the running log drain when the custom config is not allowed', function () {
    $this->server->settings->update([
        'logdrain_type' => LogDrainType::Custom,
        'logdrain_config' => ['custom' => ['config' => "[OUTPUT]\n    Name exec\n"]],
    ]);

    $scripts = new Collection;
    Process::fake(function ($process) use ($scripts) {
        $scripts->push(remoteProcessScript($process));

        return Process::result();
    });

    expect(fn () => StartLogDrain::run($this->server->fresh()))->toThrow(Exception::class, 'exec plugin')
        ->and($scripts->implode("\n"))->not->toContain('docker rm -f coolify-log-drain');
});

/**
 * Fails when the generated log drain files change. Bump StartLogDrain::CONFIG_VERSION, so that the
 * server checks restart running log drains with the new files, then update the hash.
 */
it('changes the configuration version when the generated files change', function () {
    $hashes = collect([
        'newrelic' => ['logdrain_type' => LogDrainType::NewRelic, 'logdrain_config' => ['newrelic' => ['license_key' => 'key', 'base_uri' => 'https://log-api.newrelic.com/log/v1']]],
        'axiom' => [],
        'custom' => ['logdrain_type' => LogDrainType::Custom, 'logdrain_config' => ['custom' => ['config' => "[OUTPUT]\n    Name stdout\n", 'parser_config' => "[PARSER]\n    Name p\n"]]],
    ])->map(function (array $settings) {
        $this->server->settings->update($settings);
        $scripts = startLogDrainScripts($this->server);

        return collect(['fluent-bit.conf', 'parsers.conf', 'docker-compose.yml', 'README.md'])
            ->map(fn (string $file) => generatedLogDrainFile($scripts, $file))
            ->implode("\0");
    });

    expect([StartLogDrain::CONFIG_VERSION, hash('sha256', $hashes->implode("\0"))])
        ->toBe(['3', '68fd4094e547969d6a7ba1e5b4939db03bbf5ac97a625a57b7937c1789cdcea8']);
});
