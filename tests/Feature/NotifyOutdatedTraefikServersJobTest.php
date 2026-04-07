<?php

use App\Enums\ProxyTypes;
use App\Jobs\NotifyOutdatedTraefikServersJob;
use App\Models\Server;
use App\Models\Team;
use App\Notifications\Server\TraefikVersionOutdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
});

it('has correct queue and retry configuration', function () {
    $team = Team::factory()->create();
    $scanId = 'scan-2026-03-29';
    $job = new NotifyOutdatedTraefikServersJob($team->id, $scanId);

    expect($job->tries)->toBe(3);
    expect($job->queue)->toBe('high');
    expect($job->teamId)->toBe($team->id);
    expect($job->scanId)->toBe($scanId);
});

it('sends one aggregated notification for the requested team only', function () {
    $scanId = 'scan-2026-03-29';
    $checkedAt = '2026-03-29T00:00:00+00:00';

    $team1 = Team::factory()->create();
    $team2 = Team::factory()->create();
    $team1->emailNotificationSettings->update([
        'use_instance_email_settings' => true,
        'traefik_outdated_email_notifications' => true,
    ]);
    $team2->emailNotificationSettings->update([
        'use_instance_email_settings' => true,
        'traefik_outdated_email_notifications' => true,
    ]);

    $server1 = Server::factory()->create([
        'team_id' => $team1->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
        'traefik_outdated_info' => [
            'current' => '3.5.0',
            'latest' => '3.5.6',
            'type' => 'patch_update',
            'scan_id' => $scanId,
            'checked_at' => $checkedAt,
        ],
    ]);
    $server1->settings->update(['is_reachable' => true, 'is_usable' => true]);

    $server2 = Server::factory()->create([
        'team_id' => $team1->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
        'traefik_outdated_info' => [
            'current' => '3.5.6',
            'latest' => '3.6.2',
            'type' => 'minor_upgrade',
            'upgrade_target' => 'v3.6',
            'scan_id' => $scanId,
            'checked_at' => $checkedAt,
        ],
    ]);
    $server2->settings->update(['is_reachable' => true, 'is_usable' => true]);

    $server3 = Server::factory()->create([
        'team_id' => $team2->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
        'traefik_outdated_info' => [
            'current' => '3.4.0',
            'latest' => '3.4.9',
            'type' => 'patch_update',
            'scan_id' => $scanId,
            'checked_at' => $checkedAt,
        ],
    ]);
    $server3->settings->update(['is_reachable' => true, 'is_usable' => true]);

    $previousBatchServer = Server::factory()->create([
        'team_id' => $team1->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
        'traefik_outdated_info' => [
            'current' => '3.3.0',
            'latest' => '3.3.9',
            'type' => 'patch_update',
            'scan_id' => 'scan-previous',
            'checked_at' => $checkedAt,
        ],
    ]);
    $previousBatchServer->settings->update(['is_reachable' => true, 'is_usable' => true]);

    $unusableServer = Server::factory()->create([
        'team_id' => $team2->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
        'traefik_outdated_info' => [
            'current' => '3.4.1',
            'latest' => '3.4.9',
            'type' => 'patch_update',
            'scan_id' => $scanId,
            'checked_at' => $checkedAt,
        ],
    ]);
    $unusableServer->settings->update(['is_reachable' => true, 'is_usable' => false]);

    $otherProxyServer = Server::factory()->create([
        'team_id' => $team2->id,
        'proxy' => ['type' => ProxyTypes::CADDY->value],
        'traefik_outdated_info' => [
            'current' => '3.4.1',
            'latest' => '3.4.9',
            'type' => 'patch_update',
            'scan_id' => $scanId,
            'checked_at' => $checkedAt,
        ],
    ]);
    $otherProxyServer->settings->update(['is_reachable' => true, 'is_usable' => true]);

    $job = new NotifyOutdatedTraefikServersJob($team1->id, $scanId);
    $job->handle();

    Notification::assertSentTo($team1, TraefikVersionOutdated::class, function (TraefikVersionOutdated $notification) use ($server1, $server2) {
        return $notification->servers->pluck('id')->sort()->values()->all() === collect([$server1->id, $server2->id])->sort()->values()->all();
    });

    Notification::assertNotSentTo($team2, TraefikVersionOutdated::class);
    expect(count(Notification::sent($team1, TraefikVersionOutdated::class)))->toBe(1);
});

it('does not send notifications when no servers match the batch scan id', function () {
    $scanId = 'scan-2026-03-29';
    $team = Team::factory()->create();
    $team->emailNotificationSettings->update([
        'use_instance_email_settings' => true,
        'traefik_outdated_email_notifications' => true,
    ]);

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
        'traefik_outdated_info' => [
            'current' => '3.5.0',
            'latest' => '3.5.6',
            'type' => 'patch_update',
            'scan_id' => 'scan-previous',
            'checked_at' => '2026-03-29T00:00:00+00:00',
        ],
    ]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true]);

    $job = new NotifyOutdatedTraefikServersJob($team->id, $scanId);
    $job->handle();

    Notification::assertNothingSent();
});

it('ignores servers from other scans even when checked_at matches', function () {
    $scanId = 'scan-current';
    $checkedAt = '2026-03-29T00:00:00+00:00';
    $team = Team::factory()->create();
    $team->emailNotificationSettings->update([
        'use_instance_email_settings' => true,
        'traefik_outdated_email_notifications' => true,
    ]);

    $matchingServer = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
        'traefik_outdated_info' => [
            'current' => '3.5.0',
            'latest' => '3.5.6',
            'type' => 'patch_update',
            'scan_id' => $scanId,
            'checked_at' => $checkedAt,
        ],
    ]);
    $matchingServer->settings->update(['is_reachable' => true, 'is_usable' => true]);

    $sameTimestampDifferentScan = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
        'traefik_outdated_info' => [
            'current' => '3.4.0',
            'latest' => '3.4.9',
            'type' => 'patch_update',
            'scan_id' => 'scan-other',
            'checked_at' => $checkedAt,
        ],
    ]);
    $sameTimestampDifferentScan->settings->update(['is_reachable' => true, 'is_usable' => true]);

    $job = new NotifyOutdatedTraefikServersJob($team->id, $scanId);
    $job->handle();

    Notification::assertSentTo($team, TraefikVersionOutdated::class, function (TraefikVersionOutdated $notification) use ($matchingServer) {
        return $notification->servers->pluck('id')->all() === [$matchingServer->id];
    });
});
