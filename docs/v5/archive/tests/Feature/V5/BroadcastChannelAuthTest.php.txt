<?php

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    resetV5DashboardTestState();
    createSharedUserAndTeamTables();

    Config::set('broadcasting.default', 'pusher');
    Config::set('broadcasting.connections.pusher.key', 'test-key');
    Config::set('broadcasting.connections.pusher.secret', 'test-secret');
    Config::set('broadcasting.connections.pusher.app_id', 'test-app-id');
    Config::set('broadcasting.connections.pusher.options', [
        'host' => '127.0.0.1',
        'port' => 6001,
        'scheme' => 'http',
        'useTLS' => false,
    ]);
});

it('authorizes team members on their private team channel', function () {
    [$user, $team] = createV5UserWithTeam();

    $this
        ->actingAs($user)
        ->postJson('/broadcasting/auth', [
            'channel_name' => "private-team.{$team->id}",
            'socket_id' => '1234.5678',
        ])
        ->assertSuccessful()
        ->assertJsonStructure(['auth']);
});

it('rejects users outside the team on the private team channel', function () {
    [, $team] = createV5UserWithTeam();

    $outsider = User::withoutEvents(fn () => User::query()->create([
        'name' => 'Outsider',
        'email' => 'outsider@example.com',
        'email_verified_at' => now(),
        'password' => 'password',
    ]));
    $otherTeam = Team::withoutEvents(fn () => Team::query()->create([
        'name' => 'Another Team',
        'description' => null,
        'personal_team' => false,
        'show_boarding' => false,
    ]));
    $outsider->teams()->attach($otherTeam, ['role' => 'owner']);

    $this
        ->actingAs($outsider)
        ->postJson('/broadcasting/auth', [
            'channel_name' => "private-team.{$team->id}",
            'socket_id' => '1234.5678',
        ])
        ->assertForbidden();
});

it('rejects guests on the private team channel', function () {
    [, $team] = createV5UserWithTeam();

    $this
        ->postJson('/broadcasting/auth', [
            'channel_name' => "private-team.{$team->id}",
            'socket_id' => '1234.5678',
        ])
        ->assertForbidden();
});
