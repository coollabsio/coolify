<?php

use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('disables (non-destructively) self-hosted servers with unreachable_count >= 3 after 7 days', function () {
    config(['constants.coolify.self_hosted' => true]);

    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'unreachable_count' => 50,
        'unreachable_notification_sent' => true,
        'updated_at' => now()->subDays(8),
    ]);

    $originalIp = (string) $server->ip;

    $this->artisan('cleanup:unreachable-servers')->assertSuccessful();

    $server->refresh();
    // IP must be preserved — never overwritten on self-hosted.
    expect($server->ip)->toBe($originalIp);
    expect($server->settings->force_disabled)->toBeTrue();
});

it('overwrites the IP with 1.2.3.4 on cloud for servers with unreachable_count >= 3 after 7 days', function () {
    config(['constants.coolify.self_hosted' => false]);

    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'unreachable_count' => 50,
        'unreachable_notification_sent' => true,
        'updated_at' => now()->subDays(8),
    ]);

    $this->artisan('cleanup:unreachable-servers')->assertSuccessful();

    $server->refresh();
    expect($server->ip)->toBe('1.2.3.4');
});

it('does not clean up servers with unreachable_count less than 3', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'unreachable_count' => 2,
        'unreachable_notification_sent' => true,
        'updated_at' => now()->subDays(8),
    ]);

    $originalIp = (string) $server->ip;

    $this->artisan('cleanup:unreachable-servers')->assertSuccessful();

    $server->refresh();
    expect($server->ip)->toBe($originalIp);
    expect($server->settings->force_disabled)->toBeFalse();
});

it('does not clean up servers updated within 7 days', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'unreachable_count' => 10,
        'unreachable_notification_sent' => true,
        'updated_at' => now()->subDays(3),
    ]);

    $originalIp = (string) $server->ip;

    $this->artisan('cleanup:unreachable-servers')->assertSuccessful();

    $server->refresh();
    expect($server->ip)->toBe($originalIp);
    expect($server->settings->force_disabled)->toBeFalse();
});

it('does not clean up servers without notification sent', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'unreachable_count' => 10,
        'unreachable_notification_sent' => false,
        'updated_at' => now()->subDays(8),
    ]);

    $originalIp = (string) $server->ip;

    $this->artisan('cleanup:unreachable-servers')->assertSuccessful();

    $server->refresh();
    expect($server->ip)->toBe($originalIp);
    expect($server->settings->force_disabled)->toBeFalse();
});
