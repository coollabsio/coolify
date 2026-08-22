<?php

use App\Models\Server;
use App\Models\Subscription;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('sets unreachable fields on servers when subscription ends', function () {
    $team = Team::factory()->create();
    Subscription::create([
        'team_id' => $team->id,
        'stripe_invoice_paid' => true,
    ]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'unreachable_count' => 0,
        'unreachable_notification_sent' => false,
    ]);

    $team->subscriptionEnded();

    $server->refresh();
    expect($server->unreachable_count)->toBe(3);
    expect($server->unreachable_notification_sent)->toBeTrue();
});

it('cleans up unsubscribed server IP after 7 days via cleanup command', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'unreachable_count' => 3,
        'unreachable_notification_sent' => true,
        'updated_at' => now()->subDays(8),
    ]);

    $this->artisan('cleanup:unreachable-servers')->assertSuccessful();

    $server->refresh();
    expect($server->ip)->toBe('1.2.3.4');
});

it('does not clean up unsubscribed server IP within 7 day grace period', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'unreachable_count' => 3,
        'unreachable_notification_sent' => true,
        'updated_at' => now()->subDays(3),
    ]);

    $originalIp = (string) $server->ip;

    $this->artisan('cleanup:unreachable-servers')->assertSuccessful();

    $server->refresh();
    expect((string) $server->ip)->toBe($originalIp);
});

it('does not affect servers with active subscriptions', function () {
    $team = Team::factory()->create();
    Subscription::create([
        'team_id' => $team->id,
        'stripe_invoice_paid' => true,
    ]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'unreachable_count' => 0,
        'unreachable_notification_sent' => false,
    ]);

    $originalCount = $server->unreachable_count;
    $originalNotification = $server->unreachable_notification_sent;

    expect($originalCount)->toBe(0);
    expect($originalNotification)->toBeFalse();
});
