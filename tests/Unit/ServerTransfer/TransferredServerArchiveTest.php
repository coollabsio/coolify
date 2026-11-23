<?php

use App\Actions\Server\DeleteServer;
use App\Jobs\CheckMissingDatabaseBackupsJob;
use App\Jobs\CheckMissingVolumeBackupsJob;
use App\Jobs\ServerCloudProviderStatusCheckJob;
use App\Jobs\ServerManagerJob;
use App\Livewire\Server\CloudflareTunnel;
use App\Livewire\Server\Show;
use App\Models\Application;
use App\Models\CloudProviderToken;
use App\Models\DnsProviderZone;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\LocalPersistentVolume;
use App\Models\ManagedDnsRecord;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledVolumeBackup;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Database\BackupMissing;
use App\Services\CloudflareTunnelService;
use App\Services\Dns\CloudflareDnsProvider;
use App\Services\ServerTransfer\ServerTransferClaimer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * On the source, a transferred server is only an archive: another instance manages the host, its cloud server,
 * its Cloudflare tunnel, and its DNS records.
 */
beforeEach(function () {
    Server::flushIdentityMap();
    Process::fake();
    Storage::fake('ssh-keys');
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true, 'instance_timezone' => 'UTC']);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->cloudToken = CloudProviderToken::factory()->create(['team_id' => $this->team->id, 'provider' => 'hetzner']);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
        'ip' => '203.0.113.10',
        'hetzner_server_id' => 4242,
        'cloud_provider_token_id' => $this->cloudToken->id,
    ]);
    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $this->team->id])->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id, 'destination_id' => $destination->id, 'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://shop.example.com',
    ]);
    $this->cloudflare = IntegrationToken::factory()->create(['team_id' => $this->team->id, 'provider' => 'cloudflare', 'token' => 'cf', 'capabilities' => ['dns', 'tunnel']]);
    $this->zone = DnsProviderZone::factory()->create(['integration_token_id' => $this->cloudflare->id, 'provider_zone_id' => 'zone-1', 'name' => 'example.com', 'account_id' => 'acc-1']);
});

function archiveTransferredServer(Server $server): Server
{
    app(ServerTransferClaimer::class)->markTransferred($server, targetInstanceUrl: 'https://target.test');

    return Server::query()->with('settings')->findOrFail($server->id);
}

test('the API does not delete the cloud server of an archive', function () {
    Http::fake();
    $server = archiveTransferredServer($this->server);
    session(['currentTeam' => $this->team]);
    $token = $this->user->createToken('api', ['*'])->plainTextToken;

    $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
        ->deleteJson("/api/v1/servers/{$server->uuid}?delete_from_provider=true")
        ->assertStatus(422);

    Http::assertNothingSent();
    expect(Server::whereKey($server->id)->exists())->toBeTrue();
});

test('the API deletes only the records of an archive, like the server page', function () {
    Http::fake();
    Queue::fake();
    $server = archiveTransferredServer($this->server);
    session(['currentTeam' => $this->team]);
    $token = $this->user->createToken('api', ['*'])->plainTextToken;

    $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
        ->deleteJson("/api/v1/servers/{$server->uuid}")
        ->assertOk();

    expect(Server::withTrashed()->whereKey($server->id)->exists())->toBeFalse()
        ->and(Application::withTrashed()->whereKey($this->application->id)->exists())->toBeFalse();
    Http::assertNothingSent();
    Process::assertNothingRan();
    Queue::assertNotPushed(DeleteServer::class);
});

test('the delete action never deletes the cloud server of an archive', function () {
    Http::fake();
    $server = archiveTransferredServer($this->server);
    $server->delete();

    DeleteServer::run($server->id, true, 4242, $this->cloudToken->id, $this->team->id);

    Http::assertNothingSent();
});

test('the server page does not power on the cloud server of an archive', function () {
    Http::fake();
    $server = archiveTransferredServer($this->server);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    Livewire::test(Show::class, ['server_uuid' => $server->uuid])->call('startHetznerServer');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/actions/poweron'));
});

test('the Cloudflare tunnel of an archive is not changed at Cloudflare', function () {
    Http::fake();
    $this->server->settings->forceFill(['is_cloudflare_tunnel' => true, 'cloudflare_tunnel_id' => 'tun-1', 'cloudflare_tunnel_account_id' => 'acc-1', 'cloudflare_tunnel_integration_token_id' => $this->cloudflare->id])->saveQuietly();
    $server = archiveTransferredServer($this->server);
    $tunnels = app(CloudflareTunnelService::class);

    $warnings = $tunnels->tearDown($server);

    expect($warnings)->toBe(['This server is managed by another Coolify instance.'])
        ->and(fn () => $tunnels->setUp($server, $this->cloudflare, 'ssh.example.com'))->toThrow(RuntimeException::class, 'managed by another Coolify instance')
        ->and($server->fresh()->settings->cloudflare_tunnel_id)->toBe('tun-1');
    Http::assertNothingSent();
});

test('the tunnel settings of an archive cannot disable its Cloudflare tunnel', function () {
    Http::fake();
    $this->server->settings->forceFill(['is_cloudflare_tunnel' => true, 'cloudflare_tunnel_id' => 'tun-1', 'cloudflare_tunnel_account_id' => 'acc-1', 'cloudflare_tunnel_integration_token_id' => $this->cloudflare->id])->saveQuietly();
    $server = archiveTransferredServer($this->server);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    Livewire::test(CloudflareTunnel::class, ['server_uuid' => $server->uuid])->call('toggleCloudflareTunnels');

    expect((bool) $server->fresh()->settings->is_cloudflare_tunnel)->toBeTrue();
    Http::assertNothingSent();
});

test('DNS records of the resources of an archive are not created or replaced', function () {
    fakeCloudflareDns();
    archiveTransferredServer($this->server);
    $dns = app(CloudflareDnsProvider::class);

    expect(fn () => $dns->createRecord($this->zone, 'shop.example.com', '203.0.113.10', $this->application->fresh()))
        ->toThrow(RuntimeException::class, 'managed by another Coolify instance')
        ->and(fn () => $dns->replaceRecord($this->zone, 'record-1', 'shop.example.com', '203.0.113.10', $this->application->fresh()))
        ->toThrow(RuntimeException::class, 'managed by another Coolify instance')
        ->and(sentDnsRequests('POST') + sentDnsRequests('PUT') + sentDnsRequests('PATCH'))->toBe(0);
});

test('scp does not connect to an archive', function () {
    $server = archiveTransferredServer($this->server);

    expect(fn () => instant_scp('/tmp/local-file', '/tmp/remote-file', $server))->toThrow(RuntimeException::class, 'Server is disabled.');

    Process::assertNothingRan();
});

test('the source keeps the DNS record of the Cloudflare tunnel only for a take back', function () {
    $record = ManagedDnsRecord::factory()->owned()->create(['team_id' => $this->team->id, 'dns_provider_zone_id' => $this->zone->id, 'integration_token_id' => $this->cloudflare->id, 'name' => 'ssh.example.com']);
    $record->addReference($this->server);

    archiveTransferredServer($this->server);

    expect($record->fresh()->owned)->toBeFalse();
});

test('missing backups of an archive are not reported', function () {
    Notification::fake();
    $this->team->emailNotificationSettings->update(['smtp_enabled' => true, 'backup_failure_email_notifications' => true]);
    $database = StandalonePostgresql::withoutEvents(fn () => StandalonePostgresql::forceCreate([
        'uuid' => new_public_id(), 'name' => 'archived-db', 'postgres_password' => 'secret',
        'environment_id' => $this->application->environment_id, 'destination_id' => $this->application->destination_id, 'destination_type' => $this->application->destination_type,
    ]));
    $backup = ScheduledDatabaseBackup::create([
        'enabled' => true, 'frequency' => '0 0 * * *', 'save_s3' => false, 'team_id' => $this->team->id, 'missing_backup_notification_days' => 2,
        'database_type' => $database->getMorphClass(), 'database_id' => $database->id,
    ]);
    ScheduledDatabaseBackup::whereKey($backup->id)->update(['created_at' => now()->subDays(3)]);
    archiveTransferredServer($this->server);

    (new CheckMissingDatabaseBackupsJob)->handle();

    Notification::assertNotSentTo($this->team, BackupMissing::class);
});

test('missing volume backups of an archive are not reported', function () {
    Notification::fake();
    $this->team->emailNotificationSettings->update(['smtp_enabled' => true, 'backup_failure_email_notifications' => true]);
    $volume = LocalPersistentVolume::create(['name' => 'archived-data', 'mount_path' => '/data', 'resource_type' => $this->application->getMorphClass(), 'resource_id' => $this->application->id]);
    $backup = ScheduledVolumeBackup::create([
        'backupable_type' => $volume->getMorphClass(), 'backupable_id' => $volume->id, 'team_id' => $this->team->id,
        'frequency' => '0 0 * * *', 'enabled' => true, 'missing_backup_notification_days' => 2,
    ]);
    ScheduledVolumeBackup::whereKey($backup->id)->update(['created_at' => now()->subDays(3)]);
    archiveTransferredServer($this->server);

    (new CheckMissingVolumeBackupsJob)->handle();

    Notification::assertNothingSent();
});

test('the cloud provider state of an archive is not checked', function () {
    // A minute at which the provider state check is due.
    Carbon::setTestNow('2026-07-11 12:00:00');
    Queue::fake();
    archiveTransferredServer($this->server);

    (new ServerManagerJob)->handle();

    Queue::assertNotPushed(ServerCloudProviderStatusCheckJob::class);
    Carbon::setTestNow();
});
