<?php

use App\Models\InstanceSettings;
use App\Models\Server;
use App\Notifications\Server\ServerPatchCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create a real InstanceSettings record in the test database
    // This avoids Mockery alias/overload issues that pollute global state
    $this->setInstanceSettings = function ($fqdn = null, $publicIpv4 = null, $publicIpv6 = null) {
        InstanceSettings::query()->delete();
        InstanceSettings::create([
            'id' => 0,
            'fqdn' => $fqdn,
            'public_ipv4' => $publicIpv4,
            'public_ipv6' => $publicIpv6,
        ]);
    };

    $this->createMockServer = function ($uuid, $name = 'Test Server') {
        $mockServer = Mockery::mock(Server::class);
        $mockServer->shouldReceive('getAttribute')
            ->with('uuid')
            ->andReturn($uuid);
        $mockServer->shouldReceive('getAttribute')
            ->with('name')
            ->andReturn($name);
        $mockServer->shouldReceive('setAttribute')->andReturnSelf();
        $mockServer->shouldReceive('getSchemalessAttributes')->andReturn([]);
        $mockServer->uuid = $uuid;
        $mockServer->name = $name;

        return $mockServer;
    };
});

afterEach(function () {
    Mockery::close();
});

it('generates url using base_url instead of APP_URL', function () {
    // Set InstanceSettings to return a specific FQDN
    ($this->setInstanceSettings)('https://coolify.example.com');

    $mockServer = ($this->createMockServer)('test-server-uuid');

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
    // Set InstanceSettings to return public IPv4
    ($this->setInstanceSettings)(null, '192.168.1.100');

    $mockServer = ($this->createMockServer)('test-server-uuid');

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
    ($this->setInstanceSettings)('https://coolify.test');

    $mockServer = ($this->createMockServer)('abc-123', 'Test Server');

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
    ($this->setInstanceSettings)('https://coolify.production.com');

    $mockServer = ($this->createMockServer)('error-server-uuid', 'Error Server');

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
