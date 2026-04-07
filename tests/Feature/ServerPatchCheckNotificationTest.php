<?php

use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Notifications\Server\ServerPatchCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

it('accepts a collection of servers', function () {
    $team = Team::factory()->create();
    $server1 = Server::factory()->create([
        'team_id' => $team->id,
        'patch_check_data' => ['total_updates' => 5, 'updates' => [], 'osId' => 'ubuntu', 'package_manager' => 'apt'],
    ]);
    $server2 = Server::factory()->create([
        'team_id' => $team->id,
        'patch_check_data' => ['total_updates' => 3, 'updates' => [], 'osId' => 'debian', 'package_manager' => 'apt'],
    ]);

    $notification = new ServerPatchCheck(collect([$server1, $server2]));

    expect($notification->servers)->toHaveCount(2);
});

it('generates correct mail with multiple servers', function () {
    $team = Team::factory()->create();
    $server1 = Server::factory()->create([
        'name' => 'Server 1',
        'team_id' => $team->id,
        'uuid' => 'uuid-1',
        'patch_check_data' => ['total_updates' => 5, 'updates' => [], 'osId' => 'ubuntu', 'package_manager' => 'apt'],
    ]);
    $server2 = Server::factory()->create([
        'name' => 'Server 2',
        'team_id' => $team->id,
        'uuid' => 'uuid-2',
        'patch_check_data' => ['error' => 'Connection failed', 'osId' => 'debian', 'package_manager' => 'apt'],
    ]);

    $notification = new ServerPatchCheck(collect([$server1, $server2]));
    $mail = $notification->toMail($team);

    expect($mail->viewData['count'])->toBe(2);
    expect($mail->viewData['servers'])->toHaveCount(2);
    expect($mail->viewData['servers'][0]['url'])->toBe('https://coolify.test/server/uuid-1/security/patches');
    expect($mail->viewData['servers'][1]['url'])->toBe('https://coolify.test/server/uuid-2/security/patches');
});

it('generates correct discord message with updates and errors', function () {
    $team = Team::factory()->create();
    $server1 = Server::factory()->create([
        'name' => 'Server 1',
        'team_id' => $team->id,
        'patch_check_data' => ['total_updates' => 10, 'updates' => [], 'osId' => 'ubuntu', 'package_manager' => 'apt'],
    ]);
    $server2 = Server::factory()->create([
        'name' => 'Server 2',
        'team_id' => $team->id,
        'patch_check_data' => ['error' => 'Timeout', 'osId' => 'debian', 'package_manager' => 'apt'],
    ]);

    $notification = new ServerPatchCheck(collect([$server1, $server2]));
    $discord = $notification->toDiscord();

    expect($discord->description)->toContain('Server 1')
        ->and($discord->description)->toContain('10 updates available')
        ->and($discord->description)->toContain('Server 2')
        ->and($discord->description)->toContain('failed to check updates');
});

it('generates correct webhook payload for multiple servers', function () {
    $team = Team::factory()->create();
    $server1 = Server::factory()->create([
        'name' => 'Server 1',
        'team_id' => $team->id,
        'uuid' => 'uuid-1',
        'patch_check_data' => ['total_updates' => 5, 'updates' => [], 'osId' => 'ubuntu', 'package_manager' => 'apt'],
    ]);
    $server2 = Server::factory()->create([
        'name' => 'Server 2',
        'team_id' => $team->id,
        'uuid' => 'uuid-2',
        'patch_check_data' => ['error' => 'Connection failed', 'osId' => 'debian', 'package_manager' => 'apt'],
    ]);

    $notification = new ServerPatchCheck(collect([$server1, $server2]));
    $webhook = $notification->toWebhook();

    expect($webhook['affected_servers_count'])->toBe(2);
    expect($webhook['servers'])->toHaveCount(2);
    expect($webhook['servers'][0]['server_name'])->toBe('Server 1');
    expect($webhook['servers'][0]['event'])->toBe('server_patch_check');
    expect($webhook['servers'][1]['server_name'])->toBe('Server 2');
    expect($webhook['servers'][1]['event'])->toBe('server_patch_check_error');
});

it('detects critical packages across multiple servers', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'name' => 'Server 1',
        'team_id' => $team->id,
        'patch_check_data' => [
            'total_updates' => 2,
            'updates' => [
                ['package' => 'docker-ce', 'current_version' => '24.0', 'new_version' => '25.0', 'architecture' => 'amd64', 'repository' => 'main'],
                ['package' => 'nginx', 'current_version' => '1.18', 'new_version' => '1.20', 'architecture' => 'amd64', 'repository' => 'main'],
            ],
            'osId' => 'ubuntu',
            'package_manager' => 'apt',
        ],
    ]);

    $notification = new ServerPatchCheck(collect([$server]));
    $discord = $notification->toDiscord();

    expect($discord->description)->toContain('1 critical package(s)');
});

it('notification channels can be retrieved', function () {
    $team = Team::factory()->create();

    $notification = new ServerPatchCheck(collect());
    $channels = $notification->via($team);

    expect($channels)->toBeArray();
});
