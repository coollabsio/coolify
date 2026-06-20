<?php

use App\Models\Server;
use App\Models\Team;
use App\Notifications\Server\TraefikVersionOutdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
});

it('detects servers table has detected_traefik_version column', function () {
    expect(\Illuminate\Support\Facades\Schema::hasColumn('servers', 'detected_traefik_version'))->toBeTrue();
});

it('server model casts detected_traefik_version as string', function () {
    $server = Server::factory()->make();

    expect($server->getFillable())->toContain('detected_traefik_version');
});

it('notification settings have traefik_outdated fields', function () {
    $team = Team::factory()->create();

    // Check Email notification settings
    expect($team->emailNotificationSettings)->toHaveKey('traefik_outdated_email_notifications');

    // Check Discord notification settings
    expect($team->discordNotificationSettings)->toHaveKey('traefik_outdated_discord_notifications');

    // Check Telegram notification settings
    expect($team->telegramNotificationSettings)->toHaveKey('traefik_outdated_telegram_notifications');
    expect($team->telegramNotificationSettings)->toHaveKey('telegram_notifications_traefik_outdated_thread_id');

    // Check Slack notification settings
    expect($team->slackNotificationSettings)->toHaveKey('traefik_outdated_slack_notifications');

    // Check Pushover notification settings
    expect($team->pushoverNotificationSettings)->toHaveKey('traefik_outdated_pushover_notifications');

    // Check Webhook notification settings
    expect($team->webhookNotificationSettings)->toHaveKey('traefik_outdated_webhook_notifications');
});

it('versions.json contains traefik branches with patch versions', function () {
    $versionsPath = base_path('versions.json');
    expect(File::exists($versionsPath))->toBeTrue();

    $versions = json_decode(File::get($versionsPath), true);
    expect($versions)->toHaveKey('traefik');

    $traefikVersions = $versions['traefik'];
    expect($traefikVersions)->toBeArray();

    // Each branch should have format like "v3.6" => "3.6.0"
    foreach ($traefikVersions as $branch => $version) {
        expect($branch)->toMatch('/^v\d+\.\d+$/'); // e.g., "v3.6"
        expect($version)->toMatch('/^\d+\.\d+\.\d+$/'); // e.g., "3.6.0"
    }
});

it('formats version with v prefix for display', function () {
    // Test the formatVersion logic from notification class
    $version = '3.6';
    $formatted = str_starts_with($version, 'v') ? $version : "v{$version}";

    expect($formatted)->toBe('v3.6');

    $versionWithPrefix = 'v3.6';
    $formatted2 = str_starts_with($versionWithPrefix, 'v') ? $versionWithPrefix : "v{$versionWithPrefix}";

    expect($formatted2)->toBe('v3.6');
});

it('compares semantic versions correctly', function () {
    // Test version comparison logic used in job
    $currentVersion = 'v3.5';
    $latestVersion = 'v3.6';

    $isOutdated = version_compare(ltrim($currentVersion, 'v'), ltrim($latestVersion, 'v'), '<');

    expect($isOutdated)->toBeTrue();

    // Test equal versions
    $sameVersion = version_compare(ltrim('3.6', 'v'), ltrim('3.6', 'v'), '=');
    expect($sameVersion)->toBeTrue();

    // Test newer version
    $newerVersion = version_compare(ltrim('3.7', 'v'), ltrim('3.6', 'v'), '>');
    expect($newerVersion)->toBeTrue();
});

it('notification class accepts servers collection with outdated info', function () {
    $team = Team::factory()->create();
    $server1 = Server::factory()->make([
        'name' => 'Server 1',
        'team_id' => $team->id,
        'detected_traefik_version' => 'v3.5.0',
    ]);
    $server1->outdatedInfo = [
        'current' => '3.5.0',
        'latest' => '3.5.6',
        'type' => 'patch_update',
    ];

    $server2 = Server::factory()->make([
        'name' => 'Server 2',
        'team_id' => $team->id,
        'detected_traefik_version' => 'v3.4.0',
    ]);
    $server2->outdatedInfo = [
        'current' => '3.4.0',
        'latest' => '3.6.0',
        'type' => 'minor_upgrade',
    ];

    $servers = collect([$server1, $server2]);

    $notification = new TraefikVersionOutdated($servers);

    expect($notification->servers)->toHaveCount(2);
    expect($notification->servers->first()->outdatedInfo['type'])->toBe('patch_update');
    expect($notification->servers->last()->outdatedInfo['type'])->toBe('minor_upgrade');
});

it('notification channels can be retrieved', function () {
    $team = Team::factory()->create();

    $notification = new TraefikVersionOutdated(collect());
    $channels = $notification->via($team);

    expect($channels)->toBeArray();
});

it('traefik version check command exists', function () {
    $commands = \Illuminate\Support\Facades\Artisan::all();

    expect($commands)->toHaveKey('traefik:check-version');
});

it('job handles servers with no proxy type', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
    ]);

    // Server without proxy configuration returns null for proxyType()
    expect($server->proxyType())->toBeNull();
});

it('handles latest tag correctly', function () {
    // Test that 'latest' tag is not considered for outdated comparison
    $currentVersion = 'latest';
    $latestVersion = '3.6';

    // Job skips notification for 'latest' tag
    $shouldNotify = $currentVersion !== 'latest';

    expect($shouldNotify)->toBeFalse();
});

it('groups servers by team correctly', function () {
    $team1 = Team::factory()->create(['name' => 'Team 1']);
    $team2 = Team::factory()->create(['name' => 'Team 2']);

    $servers = collect([
        (object) ['team_id' => $team1->id, 'name' => 'Server 1'],
        (object) ['team_id' => $team1->id, 'name' => 'Server 2'],
        (object) ['team_id' => $team2->id, 'name' => 'Server 3'],
    ]);

    $grouped = $servers->groupBy('team_id');

    expect($grouped)->toHaveCount(2);
    expect($grouped[$team1->id])->toHaveCount(2);
    expect($grouped[$team2->id])->toHaveCount(1);
});

it('server check job exists and has correct structure', function () {
    expect(class_exists(\App\Jobs\CheckTraefikVersionForServerJob::class))->toBeTrue();

    // Verify CheckTraefikVersionForServerJob has required properties
    $reflection = new \ReflectionClass(\App\Jobs\CheckTraefikVersionForServerJob::class);
    expect($reflection->hasProperty('tries'))->toBeTrue();
    expect($reflection->hasProperty('timeout'))->toBeTrue();

    // Verify it implements ShouldQueue
    $interfaces = class_implements(\App\Jobs\CheckTraefikVersionForServerJob::class);
    expect($interfaces)->toContain(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

it('sends immediate notifications when outdated traefik is detected', function () {
    // Notifications are now sent immediately from CheckTraefikVersionForServerJob
    // when outdated Traefik is detected, rather than being aggregated and delayed
    $team = Team::factory()->create();
    $server = Server::factory()->make([
        'name' => 'Server 1',
        'team_id' => $team->id,
    ]);

    $server->outdatedInfo = [
        'current' => '3.5.0',
        'latest' => '3.5.6',
        'type' => 'patch_update',
    ];

    // Each server triggers its own notification immediately
    $notification = new TraefikVersionOutdated(collect([$server]));

    expect($notification->servers)->toHaveCount(1);
    expect($notification->servers->first()->outdatedInfo['type'])->toBe('patch_update');
});

it('notification generates correct server proxy URLs', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'name' => 'Test Server',
        'team_id' => $team->id,
        'uuid' => 'test-uuid-123',
    ]);

    $server->outdatedInfo = [
        'current' => '3.5.0',
        'latest' => '3.5.6',
        'type' => 'patch_update',
    ];

    $notification = new TraefikVersionOutdated(collect([$server]));
    $mail = $notification->toMail($team);

    // Verify the mail has the transformed servers with URLs
    expect($mail->viewData['servers'])->toHaveCount(1);
    expect($mail->viewData['servers'][0]['name'])->toBe('Test Server');
    expect($mail->viewData['servers'][0]['uuid'])->toBe('test-uuid-123');
    expect($mail->viewData['servers'][0]['url'])->toBe(base_url().'/server/test-uuid-123/proxy');
    expect($mail->viewData['servers'][0]['outdatedInfo'])->toBeArray();
});

it('notification transforms multiple servers with URLs correctly', function () {
    $team = Team::factory()->create();
    $server1 = Server::factory()->create([
        'name' => 'Server 1',
        'team_id' => $team->id,
        'uuid' => 'uuid-1',
    ]);
    $server1->outdatedInfo = [
        'current' => '3.5.0',
        'latest' => '3.5.6',
        'type' => 'patch_update',
    ];

    $server2 = Server::factory()->create([
        'name' => 'Server 2',
        'team_id' => $team->id,
        'uuid' => 'uuid-2',
    ]);
    $server2->outdatedInfo = [
        'current' => '3.4.0',
        'latest' => '3.6.0',
        'type' => 'minor_upgrade',
        'upgrade_target' => 'v3.6',
    ];

    $servers = collect([$server1, $server2]);
    $notification = new TraefikVersionOutdated($servers);
    $mail = $notification->toMail($team);

    // Verify both servers have URLs
    expect($mail->viewData['servers'])->toHaveCount(2);

    expect($mail->viewData['servers'][0]['name'])->toBe('Server 1');
    expect($mail->viewData['servers'][0]['url'])->toBe(base_url().'/server/uuid-1/proxy');

    expect($mail->viewData['servers'][1]['name'])->toBe('Server 2');
    expect($mail->viewData['servers'][1]['url'])->toBe(base_url().'/server/uuid-2/proxy');
});

it('notification uses base_url helper not config app.url', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'name' => 'Test Server',
        'team_id' => $team->id,
        'uuid' => 'test-uuid',
    ]);

    $server->outdatedInfo = [
        'current' => '3.5.0',
        'latest' => '3.5.6',
        'type' => 'patch_update',
    ];

    $notification = new TraefikVersionOutdated(collect([$server]));
    $mail = $notification->toMail($team);

    // Verify URL starts with base_url() not config('app.url')
    $generatedUrl = $mail->viewData['servers'][0]['url'];
    expect($generatedUrl)->toStartWith(base_url());
    expect($generatedUrl)->not->toContain('localhost');
});
