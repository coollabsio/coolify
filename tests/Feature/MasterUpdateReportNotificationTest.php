<?php

use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\MasterUpdateReport;
use App\Notifications\Server\ServerPatchCheck;
use App\Notifications\Server\TraefikVersionOutdated;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::query()->forceCreate([
        'id' => 0,
        'fqdn' => 'https://coolify.test',
    ]);
});

afterEach(function () {
    Mockery::close();
});

it('renders only sections that contain updates', function () {
    $notification = new MasterUpdateReport([
        'coolify_upgrades' => [[
            'label' => 'Coolify',
            'summary' => '4.1.1 -> 4.1.2',
            'url' => 'https://coolify.test/settings/updates',
        ]],
        'proxy_upgrades' => [],
        'server_patches' => [],
        'container_image_updates' => [[
            'label' => 'Demo / Redis (Application)',
            'summary' => 'redis:7.2.0 -> redis:7.2.1',
            'url' => 'https://coolify.test/project/demo',
        ]],
    ], 2);

    $rendered = (string) $notification->toMail()->render();

    expect($rendered)->toContain('Coolify')
        ->toContain('Container Images')
        ->not->toContain('Proxy Upgrades')
        ->not->toContain('Server Patches');
});

it('removes the direct server patch email channel when the master report is enabled', function () {
    $team = Team::factory()->create();
    $team->emailNotificationSettings()->update([
        'use_instance_email_settings' => true,
        'server_patch_email_notifications' => true,
        'master_update_report_email_notifications' => true,
    ]);
    $team->refresh();

    $server = Mockery::mock(Server::class);
    $server->shouldReceive('getAttribute')->with('uuid')->andReturn('server-1');
    $server->shouldReceive('getAttribute')->with('name')->andReturn('Server One');
    $server->shouldReceive('setAttribute')->andReturnSelf();
    $server->shouldReceive('getSchemalessAttributes')->andReturn([]);
    $server->uuid = 'server-1';
    $server->name = 'Server One';

    $notification = new ServerPatchCheck($server, [
        'total_updates' => 1,
        'updates' => [],
        'osId' => 'ubuntu',
        'package_manager' => 'apt',
    ]);

    expect($notification->via($team))->not->toContain(EmailChannel::class);
});

it('removes the direct traefik email channel when the master report is enabled', function () {
    $team = Team::factory()->create();
    $team->emailNotificationSettings()->update([
        'use_instance_email_settings' => true,
        'traefik_outdated_email_notifications' => true,
        'master_update_report_email_notifications' => true,
    ]);
    $team->refresh();

    $server = Server::factory()->make([
        'name' => 'Proxy Server',
        'team_id' => $team->id,
    ]);
    $server->outdatedInfo = [
        'current' => '3.5.0',
        'latest' => '3.5.6',
        'type' => 'patch_update',
    ];

    $notification = new TraefikVersionOutdated(collect([$server]));

    expect($notification->via($team))->not->toContain(EmailChannel::class);
});
