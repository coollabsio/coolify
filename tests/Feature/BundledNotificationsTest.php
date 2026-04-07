<?php

use App\Enums\ProxyTypes;
use App\Jobs\SendPatchCheckNotificationJob;
use App\Jobs\SendTraefikOutdatedNotificationJob;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\SlackChannel;
use App\Notifications\Server\ServerPatchCheck;
use App\Notifications\Server\TraefikVersionOutdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(function () {
        InstanceSettings::updateOrCreate(
            ['id' => 0],
            ['fqdn' => 'https://coolify.test']
        );
    });
    Once::flush();
});

function enableAllChannels(Team $team, string $event = 'server_patch'): void
{
    $team->emailNotificationSettings->update([
        'use_instance_email_settings' => true,
        "{$event}_email_notifications" => true,
    ]);
    $team->discordNotificationSettings->update([
        'discord_enabled' => true,
        'discord_webhook_url' => 'https://discord.com/api/webhooks/test',
        "{$event}_discord_notifications" => true,
    ]);
    $team->slackNotificationSettings->update([
        'slack_enabled' => true,
        'slack_webhook_url' => 'https://hooks.slack.com/test',
        "{$event}_slack_notifications" => true,
    ]);
    $team->telegramNotificationSettings->update([
        'telegram_enabled' => true,
        'telegram_token' => 'test-token',
        'telegram_chat_id' => '123',
        "{$event}_telegram_notifications" => true,
    ]);
    $team->pushoverNotificationSettings->update([
        'pushover_enabled' => true,
        'pushover_user_key' => 'test-key',
        'pushover_api_token' => 'test-token',
        "{$event}_pushover_notifications" => true,
    ]);
    $team->webhookNotificationSettings->update([
        'webhook_enabled' => true,
        'webhook_url' => 'https://example.com/webhook',
        "{$event}_webhook_notifications" => true,
    ]);
    $team->refresh();
}

function enableBundling(Team $team, string $column = 'bundle_patch_notifications'): void
{
    $team->emailNotificationSettings->update([$column => true]);
    $team->discordNotificationSettings->update([$column => true]);
    $team->slackNotificationSettings->update([$column => true]);
    $team->telegramNotificationSettings->update([$column => true]);
    $team->pushoverNotificationSettings->update([$column => true]);
    $team->webhookNotificationSettings->update([$column => true]);
    $team->refresh();
}

// ──────────────────────────────────────────────────────────────────────
// Settings
// ──────────────────────────────────────────────────────────────────────

it('bundle settings default to false and can be toggled per event and channel', function () {
    $team = Team::factory()->create();

    // Defaults
    expect($team->emailNotificationSettings->bundle_patch_notifications)->toBeFalse();
    expect($team->emailNotificationSettings->bundle_traefik_notifications)->toBeFalse();

    // Toggle independently
    $team->emailNotificationSettings->update(['bundle_patch_notifications' => true, 'bundle_traefik_notifications' => false]);
    $team->discordNotificationSettings->update(['bundle_patch_notifications' => false, 'bundle_traefik_notifications' => true]);
    $team->refresh();

    expect($team->emailNotificationSettings->bundle_patch_notifications)->toBeTrue();
    expect($team->emailNotificationSettings->bundle_traefik_notifications)->toBeFalse();
    expect($team->discordNotificationSettings->bundle_patch_notifications)->toBeFalse();
    expect($team->discordNotificationSettings->bundle_traefik_notifications)->toBeTrue();
});

// ──────────────────────────────────────────────────────────────────────
// Channel filtering — mixed settings split correctly
// ──────────────────────────────────────────────────────────────────────

it('splits channels into bundled and unbundled based on per-channel setting', function () {
    $team = Team::factory()->create();
    enableAllChannels($team);
    // Bundle email + slack, leave the rest unbundled (default false)
    $team->emailNotificationSettings->update(['bundle_patch_notifications' => true]);
    $team->slackNotificationSettings->update(['bundle_patch_notifications' => true]);
    $team->refresh();

    $bundled = (new ServerPatchCheck(collect(), bundledOnly: true))->via($team);
    $unbundled = (new ServerPatchCheck(collect(), unbundledOnly: true))->via($team);
    $all = (new ServerPatchCheck(collect()))->via($team);

    expect($bundled)->toHaveCount(2);
    expect($bundled)->toContain(EmailChannel::class);
    expect($bundled)->toContain(SlackChannel::class);
    expect($unbundled)->toHaveCount(4);
    expect($unbundled)->toContain(DiscordChannel::class);
    // Normal via() returns all regardless of bundle setting
    expect($all)->toHaveCount(6);
});

it('traefik uses bundle_traefik_notifications column independently', function () {
    $team = Team::factory()->create();
    enableAllChannels($team, 'traefik_outdated');
    enableBundling($team, 'bundle_traefik_notifications');
    $team->discordNotificationSettings->update(['bundle_traefik_notifications' => false]);
    $team->refresh();

    $bundled = (new TraefikVersionOutdated(collect(), bundledOnly: true))->via($team);
    $unbundled = (new TraefikVersionOutdated(collect(), unbundledOnly: true))->via($team);

    expect($bundled)->toHaveCount(5);
    expect($bundled)->not->toContain(DiscordChannel::class);
    expect($unbundled)->toHaveCount(1);
    expect($unbundled)->toContain(DiscordChannel::class);
});

it('disabled channel or event type is excluded regardless of bundle setting', function () {
    $team = Team::factory()->create();
    enableAllChannels($team);
    enableBundling($team);
    // Disable discord entirely
    $team->discordNotificationSettings->update(['discord_enabled' => false]);
    // Disable server_patch event for slack
    $team->slackNotificationSettings->update(['server_patch_slack_notifications' => false]);
    $team->refresh();

    $bundled = (new ServerPatchCheck(collect(), bundledOnly: true))->via($team);

    expect($bundled)->not->toContain(DiscordChannel::class);
    expect($bundled)->not->toContain(SlackChannel::class);
    expect($bundled)->toHaveCount(4);
});

// ──────────────────────────────────────────────────────────────────────
// Patch summary job — data handling
// ──────────────────────────────────────────────────────────────────────

it('clears only batch-scoped servers and skips unreachable', function () {
    $team = Team::factory()->create();

    $reachable = Server::factory()->create([
        'team_id' => $team->id,
        'patch_check_data' => ['total_updates' => 5, 'updates' => [], 'osId' => 'ubuntu', 'package_manager' => 'apt'],
    ]);
    $reachable->settings()->update(['is_reachable' => true]);

    $unreachable = Server::factory()->create([
        'team_id' => $team->id,
        'patch_check_data' => ['total_updates' => 3, 'updates' => [], 'osId' => 'debian', 'package_manager' => 'apt'],
    ]);
    $unreachable->settings()->update(['is_reachable' => false]);

    $otherBatch = Server::factory()->create([
        'team_id' => $team->id,
        'patch_check_data' => ['total_updates' => 1, 'updates' => [], 'osId' => 'ubuntu', 'package_manager' => 'apt'],
    ]);
    $otherBatch->settings()->update(['is_reachable' => true]);

    // Only pass reachable server's ID (simulating batch scope)
    (new SendPatchCheckNotificationJob([$reachable->id]))->handle();

    $reachable->refresh();
    $unreachable->refresh();
    $otherBatch->refresh();

    expect($reachable->patch_check_data)->toBeNull();
    expect($unreachable->patch_check_data)->not->toBeNull(); // unreachable skipped
    expect($otherBatch->patch_check_data)->not->toBeNull(); // different batch untouched
});

// ──────────────────────────────────────────────────────────────────────
// Traefik summary job — deduplication
// ──────────────────────────────────────────────────────────────────────

it('only notifies servers with new checks and writes notified_at', function () {
    $team = Team::factory()->create();

    // Already notified — should be skipped
    $old = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
        'traefik_outdated_info' => [
            'current' => '3.5.0', 'latest' => '3.5.6', 'type' => 'patch_update',
            'checked_at' => now()->subDay()->toIso8601String(),
            'notified_at' => now()->toIso8601String(),
        ],
    ]);
    $old->settings()->update(['is_reachable' => true]);

    // New check — should be notified
    $new = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
        'traefik_outdated_info' => [
            'current' => '3.4.0', 'latest' => '3.5.6', 'type' => 'patch_update',
            'checked_at' => now()->toIso8601String(),
        ],
    ]);
    $new->settings()->update(['is_reachable' => true]);

    (new SendTraefikOutdatedNotificationJob)->handle();

    $old->refresh();
    $new->refresh();

    // Old server's notified_at unchanged (skipped)
    expect($old->traefik_outdated_info['notified_at'])->not->toBeNull();
    // New server now has notified_at set
    expect($new->traefik_outdated_info)->toHaveKey('notified_at');
    expect($new->traefik_outdated_info['notified_at'])->not->toBeNull();
});

// ──────────────────────────────────────────────────────────────────────
// Team isolation
// ──────────────────────────────────────────────────────────────────────

it('groups servers by team and does not leak across teams', function () {
    $team1 = Team::factory()->create();
    $team2 = Team::factory()->create();

    Server::factory()->create([
        'name' => 'Team1 Server',
        'team_id' => $team1->id,
        'patch_check_data' => ['total_updates' => 5, 'updates' => [], 'osId' => 'ubuntu', 'package_manager' => 'apt'],
    ])->settings()->update(['is_reachable' => true]);

    Server::factory()->create([
        'name' => 'Team2 Server',
        'team_id' => $team2->id,
        'patch_check_data' => ['total_updates' => 3, 'updates' => [], 'osId' => 'debian', 'package_manager' => 'apt'],
    ])->settings()->update(['is_reachable' => true]);

    $servers = Server::whereNotNull('patch_check_data')
        ->whereRelation('settings', 'is_reachable', true)
        ->with('team')
        ->get();

    $grouped = $servers->groupBy('team_id');

    expect($grouped)->toHaveCount(2);
    expect($grouped[$team1->id]->first()->name)->toBe('Team1 Server');
    expect($grouped[$team2->id]->first()->name)->toBe('Team2 Server');
});

// ──────────────────────────────────────────────────────────────────────
// Notification formatting
// ──────────────────────────────────────────────────────────────────────

it('patch notification formats all channels with multiple servers including errors', function () {
    $team = Team::factory()->create();
    $updateServer = Server::factory()->create([
        'name' => 'Web Server',
        'team_id' => $team->id,
        'uuid' => 'web-uuid',
        'patch_check_data' => [
            'total_updates' => 3,
            'updates' => [
                ['package' => 'docker-ce', 'current_version' => '24.0', 'new_version' => '25.0', 'architecture' => 'amd64', 'repository' => 'main'],
                ['package' => 'nginx', 'current_version' => '1.18', 'new_version' => '1.24', 'architecture' => 'amd64', 'repository' => 'main'],
            ],
            'osId' => 'ubuntu',
            'package_manager' => 'apt',
        ],
    ]);
    $errorServer = Server::factory()->create([
        'name' => 'DB Server',
        'team_id' => $team->id,
        'uuid' => 'db-uuid',
        'patch_check_data' => ['error' => 'Connection refused', 'osId' => 'debian', 'package_manager' => 'apt'],
    ]);

    $notification = new ServerPatchCheck(collect([$updateServer, $errorServer]));

    // Discord
    $discord = $notification->toDiscord();
    expect($discord->description)
        ->toContain('2 server(s)')
        ->toContain('Web Server')
        ->toContain('3 updates available')
        ->toContain('1 critical package(s)')
        ->toContain('DB Server')
        ->toContain('failed to check updates');

    // Mail
    $mail = $notification->toMail($team);
    expect($mail->viewData['count'])->toBe(2);
    expect($mail->viewData['servers'][0]['url'])->toContain('web-uuid');

    // Webhook
    $webhook = $notification->toWebhook();
    expect($webhook['affected_servers_count'])->toBe(2);
    expect($webhook['servers'][0]['event'])->toBe('server_patch_check');
    expect($webhook['servers'][1]['event'])->toBe('server_patch_check_error');
});

// ──────────────────────────────────────────────────────────────────────
// Edge cases
// ──────────────────────────────────────────────────────────────────────

it('handles deleted server and deleted team gracefully', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'patch_check_data' => ['total_updates' => 5, 'updates' => [], 'osId' => 'ubuntu', 'package_manager' => 'apt'],
    ]);
    $server->settings()->update(['is_reachable' => true]);
    $serverId = $server->id;

    // Delete server
    $server->settings()->delete();
    $server->delete();

    // Should not throw
    (new SendPatchCheckNotificationJob([$serverId]))->handle();
    expect(Server::find($serverId))->toBeNull();

    // Orphaned server (team deleted)
    $team2 = Team::factory()->create();
    $server2 = Server::factory()->create([
        'team_id' => $team2->id,
        'patch_check_data' => ['total_updates' => 3, 'updates' => [], 'osId' => 'debian', 'package_manager' => 'apt'],
    ]);
    $server2->settings()->update(['is_reachable' => true]);
    $team2->delete();

    // Should not throw
    (new SendPatchCheckNotificationJob([$server2->id]))->handle();
    $server2->refresh();
    expect($server2->patch_check_data)->toBeNull();
});

// ──────────────────────────────────────────────────────────────────────
// End-to-end: summary jobs send notifications via Notification::fake
// ──────────────────────────────────────────────────────────────────────

it('patch summary job sends bundled notification per team', function () {
    $team1 = Team::factory()->create();
    $team2 = Team::factory()->create();
    enableAllChannels($team1);
    enableAllChannels($team2);
    enableBundling($team1);
    enableBundling($team2);

    Notification::fake();

    Server::factory()->create([
        'name' => 'Team1 Server',
        'team_id' => $team1->id,
        'patch_check_data' => ['total_updates' => 5, 'updates' => [], 'osId' => 'ubuntu', 'package_manager' => 'apt'],
    ])->settings()->update(['is_reachable' => true]);

    Server::factory()->create([
        'name' => 'Team2 Server',
        'team_id' => $team2->id,
        'patch_check_data' => ['total_updates' => 3, 'updates' => [], 'osId' => 'debian', 'package_manager' => 'apt'],
    ])->settings()->update(['is_reachable' => true]);

    (new SendPatchCheckNotificationJob)->handle();

    Notification::assertSentTo($team1, ServerPatchCheck::class, function ($notification) {
        return $notification->servers->count() === 1
            && $notification->servers->first()->name === 'Team1 Server'
            && $notification->bundledOnly === true;
    });
    Notification::assertSentTo($team2, ServerPatchCheck::class);
});

it('traefik summary job sends bundled notification per team', function () {
    $team = Team::factory()->create();
    enableAllChannels($team, 'traefik_outdated');
    enableBundling($team, 'bundle_traefik_notifications');

    Notification::fake();

    Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
        'traefik_outdated_info' => [
            'current' => '3.5.0', 'latest' => '3.5.6', 'type' => 'patch_update',
            'checked_at' => now()->toIso8601String(),
        ],
    ])->settings()->update(['is_reachable' => true]);

    (new SendTraefikOutdatedNotificationJob)->handle();

    Notification::assertSentTo($team, TraefikVersionOutdated::class, function ($notification) {
        return $notification->servers->count() === 1 && $notification->bundledOnly === true;
    });
});

it('summary jobs do not send when no channels have bundling enabled', function () {
    $team = Team::factory()->create();
    enableAllChannels($team);
    enableAllChannels($team, 'traefik_outdated');
    // Bundling off by default — don't enable it

    Notification::fake();

    Server::factory()->create([
        'team_id' => $team->id,
        'patch_check_data' => ['total_updates' => 5, 'updates' => [], 'osId' => 'ubuntu', 'package_manager' => 'apt'],
    ])->settings()->update(['is_reachable' => true]);

    $traefikServer = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
        'traefik_outdated_info' => [
            'current' => '3.5.0', 'latest' => '3.5.6', 'type' => 'patch_update',
            'checked_at' => now()->toIso8601String(),
        ],
    ]);
    $traefikServer->settings()->update(['is_reachable' => true]);

    (new SendPatchCheckNotificationJob)->handle();
    (new SendTraefikOutdatedNotificationJob)->handle();

    Notification::assertNotSentTo($team, ServerPatchCheck::class);
    Notification::assertNotSentTo($team, TraefikVersionOutdated::class);
});
