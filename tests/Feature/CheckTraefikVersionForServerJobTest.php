<?php

use App\Enums\ProxyTypes;
use App\Jobs\CheckTraefikVersionForServerJob;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Notifications\Server\TraefikVersionOutdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('ssh-keys');
    Notification::fake();
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::unguarded(function () {
        InstanceSettings::updateOrCreate(['id' => 0], []);
    });

    $this->team = Team::factory()->create();
    $this->team->webhookNotificationSettings()->update([
        'webhook_enabled' => true,
        'webhook_url' => 'https://example.com/webhook',
    ]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value, 'status' => 'running'],
    ]);
    Event::fake();
});

/**
 * Fakes the proxy container: `traefik version` output and the image of `coolify-proxy`.
 */
function fakeTraefikProxyContainer(string $exactVersion, string $image): void
{
    fakeRemoteProcesses([
        '*traefik version*' => Process::result(output: $exactVersion),
        '*.Config.Image*' => Process::result(output: $image),
    ]);
}

function runTraefikVersionCheck(Server $server, array $traefikVersions): Server
{
    (new CheckTraefikVersionForServerJob($server, $traefikVersions))->handle();

    return $server->refresh();
}

it('stores a patch update for an outdated version on a tracked branch', function () {
    fakeTraefikProxyContainer('3.5.0', 'traefik:v3.5.0');

    $server = runTraefikVersionCheck($this->server, ['v3.5' => '3.5.6']);

    expect($server->detected_traefik_version)->toBe('3.5.0')
        ->and($server->traefik_outdated_info)->toMatchArray([
            'current' => '3.5.0',
            'latest' => '3.5.6',
            'type' => 'patch_update',
        ]);
    Notification::assertSentTo($this->team, TraefikVersionOutdated::class);
});

it('parses a v-prefixed version from the image tag', function () {
    fakeTraefikProxyContainer('', 'traefik:v3.5.0');

    $server = runTraefikVersionCheck($this->server, ['v3.5' => '3.5.6']);

    expect($server->detected_traefik_version)->toBe('v3.5.0')
        ->and($server->traefik_outdated_info)->toMatchArray([
            'current' => '3.5.0',
            'latest' => '3.5.6',
            'type' => 'patch_update',
        ]);
});

it('stores a minor upgrade to the newest branch', function (array $traefikVersions, string $latest, string $target) {
    fakeTraefikProxyContainer('3.5.6', 'traefik:v3.5');

    $server = runTraefikVersionCheck($this->server, $traefikVersions);

    expect($server->traefik_outdated_info)->toMatchArray([
        'current' => '3.5.6',
        'latest' => $latest,
        'type' => 'minor_upgrade',
        'upgrade_target' => $target,
    ]);
    Notification::assertSentTo($this->team, TraefikVersionOutdated::class);
})->with([
    'one newer branch' => [['v3.5' => '3.5.6', 'v3.6' => '3.6.2'], '3.6.2', 'v3.6'],
    'several newer branches' => [['v3.5' => '3.5.6', 'v3.6' => '3.6.2', 'v3.7' => '3.7.0'], '3.7.0', 'v3.7'],
]);

it('stores no outdated info for an up-to-date version', function () {
    fakeTraefikProxyContainer('3.6.2', 'traefik:v3.6');

    $server = runTraefikVersionCheck($this->server, ['v3.5' => '3.5.6', 'v3.6' => '3.6.2']);

    expect($server->detected_traefik_version)->toBe('3.6.2')
        ->and($server->traefik_outdated_info)->toBeNull();
    Notification::assertNothingSent();
});

it('stores no outdated info when the version is not major.minor.patch', function (string $image) {
    fakeTraefikProxyContainer('', $image);

    $server = runTraefikVersionCheck($this->server, ['v3.5' => '3.5.6', 'v3.6' => '3.6.2']);

    expect($server->traefik_outdated_info)->toBeNull();
    Notification::assertNothingSent();
})->with([
    'branch only' => 'traefik:v3.5',
    'latest' => 'traefik:latest',
]);

it('stores no outdated info when the proxy image cannot be read', function (string $image) {
    fakeTraefikProxyContainer('3.5.0', $image);

    $server = runTraefikVersionCheck($this->server, ['v3.5' => '3.5.6', 'v3.6' => '3.6.2']);

    expect($server->detected_traefik_version)->toBe('3.5.0')
        ->and($server->traefik_outdated_info)->toBeNull();
    Notification::assertNothingSent();
})->with([
    'empty' => '',
    'whitespace only' => "   \n  ",
]);

it('stores no outdated info when the proxy image uses the latest tag', function (string $image) {
    fakeTraefikProxyContainer('3.5.0', $image);

    $server = runTraefikVersionCheck($this->server, ['v3.5' => '3.5.6', 'v3.6' => '3.6.2']);

    expect($server->detected_traefik_version)->toBe('3.5.0')
        ->and($server->traefik_outdated_info)->toBeNull();
    Notification::assertNothingSent();
})->with(['traefik:latest', 'traefik:Latest', 'traefik:LATEST']);

it('notifies about an outdated Traefik only once per patch version or upgrade target', function (string $exactVersion, string $image, array $traefikVersions, array $previousInfo, bool $notified) {
    $this->server->update(['traefik_outdated_info' => $previousInfo]);
    fakeTraefikProxyContainer($exactVersion, $image);

    runTraefikVersionCheck($this->server, $traefikVersions);

    $notified
        ? Notification::assertSentTo($this->team, TraefikVersionOutdated::class)
        : Notification::assertNothingSent();
})->with([
    'same patch version' => ['3.7.7', 'traefik:v3.7.7', ['v3.7' => '3.7.13'], ['current' => '3.7.7', 'latest' => '3.7.13', 'type' => 'patch_update'], false],
    'newer patch version' => ['3.7.7', 'traefik:v3.7.7', ['v3.7' => '3.7.16'], ['current' => '3.7.7', 'latest' => '3.7.13', 'type' => 'patch_update'], true],
    'same upgrade target' => ['3.6.20', 'traefik:v3.6', ['v3.6' => '3.6.20', 'v3.7' => '3.7.13'], ['current' => '3.6.20', 'latest' => '3.7.13', 'type' => 'minor_upgrade', 'upgrade_target' => 'v3.7'], false],
    'newer upgrade target' => ['3.6.20', 'traefik:v3.6', ['v3.6' => '3.6.20', 'v3.7' => '3.7.13', 'v3.8' => '3.8.1'], ['current' => '3.6.20', 'latest' => '3.7.13', 'type' => 'minor_upgrade', 'upgrade_target' => 'v3.7'], true],
]);

it('clears the stale Traefik version state when the current version cannot be detected', function () {
    $this->server->update([
        'detected_traefik_version' => '3.6.23',
        'traefik_outdated_info' => ['current' => '3.6.23', 'latest' => '3.7.8', 'type' => 'minor_upgrade', 'upgrade_target' => 'v3.7'],
    ]);
    fakeTraefikProxyContainer('', '');

    $server = runTraefikVersionCheck($this->server, ['v3.7' => '3.7.8']);

    expect($server->detected_traefik_version)->toBeNull()
        ->and($server->traefik_outdated_info)->toBeNull();
    Notification::assertNothingSent();
});
