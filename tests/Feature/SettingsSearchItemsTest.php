<?php

use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function serverSettingsSearchGroups(): array
{
    return [
        'Security' => [
            [
                'label' => 'Security',
                'route' => 'server.security.patches',
                'children' => [
                    ['label' => 'Server Patching', 'route' => 'server.security.patches'],
                    ['label' => 'Terminal Access', 'route' => 'server.security.terminal-access', 'navigate' => false],
                    ['label' => 'Hidden', 'route' => 'server.security', 'visible' => false],
                ],
            ],
        ],
        'General' => [
            ['label' => 'Proxy', 'route' => 'server.proxy'],
        ],
    ];
}

it('lists pages, visible child pages and in-page sections with their breadcrumbs', function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $items = settingsSearchItems(
        serverSettingsSearchGroups(),
        ['server_uuid' => 'abc'],
        ['server.proxy' => [['id' => 'proxy-config-section', 'label' => 'Configuration']]],
    );

    expect($items)->toBe([
        ['label' => 'Security', 'breadcrumb' => 'Security', 'search_text' => 'Security Security', 'href' => route('server.security.patches', ['server_uuid' => 'abc']), 'navigate' => true],
        ['label' => 'Server Patching', 'breadcrumb' => 'Security · Security', 'search_text' => 'Server Patching Security · Security', 'href' => route('server.security.patches', ['server_uuid' => 'abc']), 'navigate' => true],
        ['label' => 'Terminal Access', 'breadcrumb' => 'Security · Security', 'search_text' => 'Terminal Access Security · Security', 'href' => route('server.security.terminal-access', ['server_uuid' => 'abc']), 'navigate' => false],
        ['label' => 'Proxy', 'breadcrumb' => 'General', 'search_text' => 'Proxy General', 'href' => route('server.proxy', ['server_uuid' => 'abc']), 'navigate' => true],
        ['label' => 'Configuration', 'breadcrumb' => 'General · Proxy', 'search_text' => 'Configuration General · Proxy', 'href' => route('server.proxy', ['server_uuid' => 'abc']).'#proxy-config-section', 'navigate' => true],
    ]);
});

it('uses full page loads when SPA navigation is disabled', function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_wire_navigate_enabled' => false]);

    $items = settingsSearchItems(serverSettingsSearchGroups(), ['server_uuid' => 'abc']);

    expect(collect($items)->pluck('navigate')->unique()->all())->toBe([false]);
});
