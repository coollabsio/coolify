<?php

use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->team = Team::factory()->create();
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
});

/**
 * Creates a server and writes the raw IP past the model setter, like rows saved by older versions.
 */
function createServerWithRawIpForMigrationTest(Team $team, PrivateKey $privateKey, string $rawIp, array $attributes = []): Server
{
    $server = Server::factory()->create(array_merge([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'ip' => '203.0.113.250',
    ], $attributes));
    DB::table('servers')->where('id', $server->id)->update(['ip' => $rawIp]);

    return $server;
}

function runNormalizeIpv6ServerIpsMigration(): void
{
    (require database_path('migrations/2026_10_09_092903_normalize_ipv6_server_ips.php'))->up();
}

function rawServerIp(Server $server): ?string
{
    return DB::table('servers')->where('id', $server->id)->value('ip');
}

it('repairs Hetzner IPv6 networks saved with the old setter', function () {
    $server = createServerWithRawIpForMigrationTest($this->team, $this->privateKey, '2a01:4f8:c016:bd23::64', [
        'hetzner_server_id' => 123,
    ]);
    $server->settings()->update(['is_reachable' => false]);

    runNormalizeIpv6ServerIpsMigration();

    expect(rawServerIp($server))->toBe('2a01:4f8:c016:bd23::1');
});

it('keeps a reachable Hetzner server whose address really ends in ::64', function () {
    $server = createServerWithRawIpForMigrationTest($this->team, $this->privateKey, '2a01:4f8:c016:bd23::64', [
        'hetzner_server_id' => 123,
    ]);
    $server->settings()->update(['is_reachable' => true]);

    runNormalizeIpv6ServerIpsMigration();

    expect(rawServerIp($server))->toBe('2a01:4f8:c016:bd23::64');
});

it('repairs Sentinel URLs that were saved with a bare IPv6 address', function () {
    $server = createServerWithRawIpForMigrationTest($this->team, $this->privateKey, '2a01:4f8:c016:bd23::1');
    $server->settings()->update(['sentinel_custom_url' => 'http://2a01:4f8:c016:bd23::1:8000']);
    $other = createServerWithRawIpForMigrationTest($this->team, $this->privateKey, '198.51.100.8');
    $other->settings()->update(['sentinel_custom_url' => 'https://coolify.example.com']);

    runNormalizeIpv6ServerIpsMigration();

    expect($server->settings()->value('sentinel_custom_url'))->toBe('http://[2a01:4f8:c016:bd23::1]:8000')
        ->and($other->settings()->value('sentinel_custom_url'))->toBe('https://coolify.example.com');
});

it('ignores deleted servers when it checks for duplicates', function () {
    $deleted = createServerWithRawIpForMigrationTest($this->team, $this->privateKey, '2001:db8::9');
    $deleted->delete();
    $server = createServerWithRawIpForMigrationTest($this->team, $this->privateKey, '2001:0DB8::9');

    runNormalizeIpv6ServerIpsMigration();

    expect(rawServerIp($server))->toBe('2001:db8::9');
});

it('normalizes IPv6 addresses and leaves IPv4 addresses and hostnames untouched', function () {
    $ipv6 = createServerWithRawIpForMigrationTest($this->team, $this->privateKey, '2A01:04F8:0000:0000:0000:0000:0000:0001');
    $notHetzner = createServerWithRawIpForMigrationTest($this->team, $this->privateKey, '2001:db8::64');
    $ipv4 = createServerWithRawIpForMigrationTest($this->team, $this->privateKey, '198.51.100.7');
    $hostname = createServerWithRawIpForMigrationTest($this->team, $this->privateKey, 'Server.Example.com');

    runNormalizeIpv6ServerIpsMigration();

    expect(rawServerIp($ipv6))->toBe('2a01:4f8::1')
        ->and(rawServerIp($notHetzner))->toBe('2001:db8::64')
        ->and(rawServerIp($ipv4))->toBe('198.51.100.7')
        ->and(rawServerIp($hostname))->toBe('Server.Example.com');
});

it('includes the server with id 0', function () {
    createServerWithRawIpForMigrationTest($this->team, $this->privateKey, '2001:0DB8::9', ['id' => 0]);

    runNormalizeIpv6ServerIpsMigration();

    expect(DB::table('servers')->where('id', 0)->value('ip'))->toBe('2001:db8::9');
});

it('skips a server when the new address belongs to another server', function () {
    Log::spy();
    $existing = createServerWithRawIpForMigrationTest($this->team, $this->privateKey, '2a01:4f8:c016:bd23::1');
    $broken = createServerWithRawIpForMigrationTest($this->team, $this->privateKey, '2a01:4f8:c016:bd23::64', [
        'hetzner_server_id' => 123,
    ]);
    $broken->settings()->update(['is_reachable' => false]);
    $otherTeam = Team::factory()->create();
    $otherTeamServer = createServerWithRawIpForMigrationTest($otherTeam, PrivateKey::factory()->create(['team_id' => $otherTeam->id]), '2001:0db8::1');
    createServerWithRawIpForMigrationTest($this->team, $this->privateKey, '2001:db8::1');

    runNormalizeIpv6ServerIpsMigration();

    expect(rawServerIp($existing))->toBe('2a01:4f8:c016:bd23::1')
        ->and(rawServerIp($broken))->toBe('2a01:4f8:c016:bd23::64')
        ->and(rawServerIp($otherTeamServer))->toBe('2001:0db8::1');
    Log::shouldHaveReceived('warning')->twice();
});
