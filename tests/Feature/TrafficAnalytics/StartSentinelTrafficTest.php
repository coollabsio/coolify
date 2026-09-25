<?php

use App\Actions\Server\StartSentinel;
use App\Enums\ServerRole;
use App\Events\SentinelRestarted;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process as SymfonyProcess;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $user = User::factory()->create();
    $this->team = $user->teams()->first();
});

it('produces no traffic env when disabled', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings->is_traffic_analytics_enabled = false;
    $server->settings->save();
    expect(StartSentinel::sentinelTrafficEnvironment($server->fresh()))->toBe([]);
});

it('produces traffic + geoip env when enabled', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings->is_traffic_analytics_enabled = true;
    $server->settings->geoip_maxmind_license_key = 'lic';
    $server->settings->save();

    $env = StartSentinel::sentinelTrafficEnvironment($server->fresh());
    expect($env['TRAFFIC_ENABLED'])->toBe('true');
    expect($env['TRAFFIC_PROXY_TYPE'])->toBe('auto');
    expect($env['TRAFFIC_TOPN'])->toBe('50');
    expect($env['TRAFFIC_SAMPLE_THRESHOLD'])->toBe('0');
    expect($env['TRAFFIC_RETENTION_1H_DAYS'])->toBe('30');
    expect($env['TRAFFIC_RETENTION_1D_DAYS'])->toBe('395');
    expect($env['GEOIP_ENABLED'])->toBe('true');
    expect($env['GEOIP_REFRESH_DAYS'])->toBe('30');
    expect($env['GEOIP_MAXMIND_LICENSE_KEY'])->toBe('lic');
    expect($env)->toHaveKey('TRAFFIC_ACCESS_LOG_PATH');
});

it('uses the dev proxy volume for traffic logs locally', function () {
    config()->set('app.env', 'local');
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings->is_traffic_analytics_enabled = true;
    $server->settings->save();

    expect(StartSentinel::trafficLogDirectory($server->fresh()))
        ->toBe('/var/lib/docker/volumes/coolify_dev_coolify_data/_data/proxy');
    expect(StartSentinel::sentinelTrafficEnvironment($server->fresh())['TRAFFIC_ACCESS_LOG_PATH'])
        ->toBe('/var/lib/docker/volumes/coolify_dev_coolify_data/_data/proxy/access.log');
});

it('passes custom traffic settings as sentinel env', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings->is_traffic_analytics_enabled = true;
    $server->settings->traffic_topn = 100;
    $server->settings->traffic_sample_threshold = 500;
    $server->settings->traffic_retention_1h_days = 14;
    $server->settings->traffic_retention_1d_days = 180;
    $server->settings->is_geoip_enabled = false;
    $server->settings->geoip_refresh_days = 7;
    // A key may still be stored while GeoIP is off; it must not reach Sentinel.
    $server->settings->geoip_maxmind_license_key = 'secret-maxmind-key';
    $server->settings->save();

    $env = StartSentinel::sentinelTrafficEnvironment($server->fresh());
    expect($env['TRAFFIC_TOPN'])->toBe('100');
    expect($env['TRAFFIC_SAMPLE_THRESHOLD'])->toBe('500');
    expect($env['TRAFFIC_RETENTION_1H_DAYS'])->toBe('14');
    expect($env['TRAFFIC_RETENTION_1D_DAYS'])->toBe('180');
    expect($env['GEOIP_ENABLED'])->toBe('false');
    expect($env['GEOIP_REFRESH_DAYS'])->toBe('7');
    expect($env)->not->toHaveKey('GEOIP_MAXMIND_LICENSE_KEY');
});

it('injects the maxmind license key only when geoip is enabled', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings->is_traffic_analytics_enabled = true;
    $server->settings->is_geoip_enabled = true;
    $server->settings->geoip_maxmind_license_key = 'secret-maxmind-key';
    $server->settings->save();

    $env = StartSentinel::sentinelTrafficEnvironment($server->fresh());
    expect($env['GEOIP_MAXMIND_LICENSE_KEY'])->toBe('secret-maxmind-key');
});

function sentinelTrafficServer(object $test, ?string $proxyType, bool $analyticsEnabled): Server
{
    $server = Server::factory()->create([
        'team_id' => $test->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $test->team->id])->id,
    ]);
    $server->proxy->set('type', $proxyType);
    $server->save();
    $server->settings->is_traffic_analytics_enabled = $analyticsEnabled;
    $server->settings->sentinel_custom_url = 'https://coolify.example.com';
    $server->settings->save();

    return $server->fresh();
}

/**
 * Runs StartSentinel against a faked SSH process and returns the remote script it sent.
 */
function runStartSentinelAndCaptureScript(Server $server): string
{
    if (! InstanceSettings::query()->whereKey(0)->exists()) {
        InstanceSettings::forceCreate(['id' => 0]);
    }
    config(['constants.ssh.mux_enabled' => false]);
    Event::fake([SentinelRestarted::class]);
    $scripts = [];
    Process::fake(function ($process) use (&$scripts) {
        $scripts[] = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        return Process::result(output: '');
    });

    StartSentinel::run($server, latestVersion: '1.0.1');

    return collect($scripts)->first(fn (string $script): bool => str_contains($script, 'docker run -d'));
}

it('creates the access log before starting sentinel with traffic analytics', function (string $proxyType, string $directory) {
    $server = sentinelTrafficServer($this, $proxyType, analyticsEnabled: true);

    expect(StartSentinel::trafficLogPreparationCommands($server))->toBe([
        'mkdir -p '.escapeshellarg($directory),
        'touch '.escapeshellarg($directory.'/access.log'),
    ]);

    $script = runStartSentinelAndCaptureScript($server);
    $touch = 'touch '.escapeshellarg($directory.'/access.log');

    expect($script)->toContain('mkdir -p '.escapeshellarg($directory))
        ->toContain($touch)
        ->not->toContain('> '.$directory.'/access.log')
        ->and(strpos($script, 'mkdir -p '.escapeshellarg($directory)))->toBeLessThan(strpos($script, $touch))
        ->and(strpos($script, $touch))->toBeLessThan(strpos($script, 'docker run -d'));
})->with([
    'traefik' => ['TRAEFIK', '/data/coolify/proxy'],
    'caddy' => ['CADDY', '/data/coolify/proxy/caddy'],
]);

it('does not touch the access log when traffic analytics cannot run', function (?string $proxyType, bool $analyticsEnabled) {
    $server = sentinelTrafficServer($this, $proxyType, $analyticsEnabled);

    expect(StartSentinel::trafficLogPreparationCommands($server))->toBe([]);

    $script = runStartSentinelAndCaptureScript($server);

    expect($script)->toContain('docker run -d')
        ->not->toContain('touch ');
})->with([
    'analytics disabled' => ['TRAEFIK', false],
    'proxy none' => ['NONE', true],
]);

it('does not touch the access log on swarm or build servers', function (string $setting, mixed $value) {
    $server = sentinelTrafficServer($this, 'TRAEFIK', analyticsEnabled: true);
    $server->settings->{$setting} = $value;
    $server->settings->save();

    expect(StartSentinel::trafficLogPreparationCommands($server->fresh()))->toBe([]);
})->with([
    'swarm' => ['is_swarm_manager', true],
    'build' => ['server_role', ServerRole::BUILD],
]);

it('keeps the access log commands valid for non-root servers', function () {
    $server = sentinelTrafficServer($this, 'TRAEFIK', analyticsEnabled: true);
    $server->user = 'ubuntu';

    $commands = parseCommandsByLineForSudo(collect(StartSentinel::trafficLogPreparationCommands($server)), $server);

    expect($commands)->toBe([
        "sudo mkdir -p '/data/coolify/proxy'",
        "sudo touch '/data/coolify/proxy/access.log'",
    ]);

    $script = tempnam(sys_get_temp_dir(), 'sentinel-traffic');
    file_put_contents($script, implode("\n", $commands)."\n");
    $syntax = SymfonyProcess::fromShellCommandline('bash -n '.escapeshellarg($script));
    $syntax->run();
    unlink($script);

    expect($syntax->isSuccessful())->toBeTrue($syntax->getErrorOutput());
});
