<?php

use App\Models\InstanceSettings;
use App\Models\Server;
use App\Notifications\Server\ServerPatchCheck;
use Mockery;

afterEach(function () {
    Mockery::close();
});

it('generates url using base_url instead of APP_URL', function () {
    // Mock InstanceSettings to return a specific FQDN
    InstanceSettings::shouldReceive('get')
        ->andReturn((object) [
            'fqdn' => 'https://coolify.example.com',
            'public_ipv4' => null,
            'public_ipv6' => null,
        ]);

    $mockServer = Mockery::mock(Server::class);
    $mockServer->shouldReceive('getAttribute')
        ->with('uuid')
        ->andReturn('test-server-uuid');
    $mockServer->uuid = 'test-server-uuid';

    $patchData = [
        'total_updates' => 5,
        'updates' => [],
        'osId' => 'ubuntu',
        'package_manager' => 'apt',
    ];

    $notification = new ServerPatchCheck($mockServer, $patchData);

    // The URL should use the FQDN from InstanceSettings, not APP_URL
    expect($notification->serverUrl)->toBe('https://coolify.example.com/server/test-server-uuid/security/patches');
});

it('falls back to public_ipv4 with port when fqdn is not set', function () {
    // Mock InstanceSettings to return public IPv4
    InstanceSettings::shouldReceive('get')
        ->andReturn((object) [
            'fqdn' => null,
            'public_ipv4' => '192.168.1.100',
            'public_ipv6' => null,
        ]);

    $mockServer = Mockery::mock(Server::class);
    $mockServer->shouldReceive('getAttribute')
        ->with('uuid')
        ->andReturn('test-server-uuid');
    $mockServer->uuid = 'test-server-uuid';

    $patchData = [
        'total_updates' => 3,
        'updates' => [],
        'osId' => 'debian',
        'package_manager' => 'apt',
    ];

    $notification = new ServerPatchCheck($mockServer, $patchData);

    // The URL should use public IPv4 with default port 8000
    expect($notification->serverUrl)->toBe('http://192.168.1.100:8000/server/test-server-uuid/security/patches');
});

it('includes server url in all notification channels', function () {
    InstanceSettings::shouldReceive('get')
        ->andReturn((object) [
            'fqdn' => 'https://coolify.test',
            'public_ipv4' => null,
            'public_ipv6' => null,
        ]);

    $mockServer = Mockery::mock(Server::class);
    $mockServer->shouldReceive('getAttribute')
        ->with('uuid')
        ->andReturn('abc-123');
    $mockServer->shouldReceive('getAttribute')
        ->with('name')
        ->andReturn('Test Server');
    $mockServer->uuid = 'abc-123';
    $mockServer->name = 'Test Server';

    $patchData = [
        'total_updates' => 10,
        'updates' => [
            [
                'package' => 'nginx',
                'current_version' => '1.18',
                'new_version' => '1.20',
                'architecture' => 'amd64',
                'repository' => 'main',
            ],
        ],
        'osId' => 'ubuntu',
        'package_manager' => 'apt',
    ];

    $notification = new ServerPatchCheck($mockServer, $patchData);

    // Check Discord
    $discord = $notification->toDiscord();
    expect($discord->description)->toContain('https://coolify.test/server/abc-123/security/patches');

    // Check Telegram
    $telegram = $notification->toTelegram();
    expect($telegram['buttons'][0]['url'])->toBe('https://coolify.test/server/abc-123/security/patches');

    // Check Pushover
    $pushover = $notification->toPushover();
    expect($pushover->buttons[0]['url'])->toBe('https://coolify.test/server/abc-123/security/patches');

    // Check Slack
    $slack = $notification->toSlack();
    expect($slack->description)->toContain('https://coolify.test/server/abc-123/security/patches');

    // Check Webhook
    $webhook = $notification->toWebhook();
    expect($webhook['url'])->toBe('https://coolify.test/server/abc-123/security/patches');
});

it('uses correct url in error notifications', function () {
    InstanceSettings::shouldReceive('get')
        ->andReturn((object) [
            'fqdn' => 'https://coolify.production.com',
            'public_ipv4' => null,
            'public_ipv6' => null,
        ]);

    $mockServer = Mockery::mock(Server::class);
    $mockServer->shouldReceive('getAttribute')
        ->with('uuid')
        ->andReturn('error-server-uuid');
    $mockServer->shouldReceive('getAttribute')
        ->with('name')
        ->andReturn('Error Server');
    $mockServer->uuid = 'error-server-uuid';
    $mockServer->name = 'Error Server';

    $patchData = [
        'error' => 'Failed to connect to package manager',
        'osId' => 'ubuntu',
        'package_manager' => 'apt',
    ];

    $notification = new ServerPatchCheck($mockServer, $patchData);

    // Check error Discord notification
    $discord = $notification->toDiscord();
    expect($discord->description)->toContain('https://coolify.production.com/server/error-server-uuid/security/patches');

    // Check error webhook
    $webhook = $notification->toWebhook();
    expect($webhook['url'])->toBe('https://coolify.production.com/server/error-server-uuid/security/patches')
        ->and($webhook['event'])->toBe('server_patch_check_error');
});
