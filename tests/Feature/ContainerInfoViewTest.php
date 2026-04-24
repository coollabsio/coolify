<?php

it('renders the read-only container info card with copy actions only for stable identifiers and IPs', function () {
    $view = view('components.container-info', [
        'containerInfo' => [
            'id' => 'c9248632fb1f1ba4b0d885f78ebadf6af6233799a645d2f5c749088dbf55d79f',
            'name' => 'web-service-uuid',
            'image' => 'ghcr.io/example/app:1.2.3',
            'created_at' => '2026-04-24T12:34:56.123456789Z',
            'started_at' => '2026-04-24T12:35:10.987654321Z',
            'ipv4_addresses' => ['172.18.0.5'],
            'ipv6_addresses' => ['fd00::5'],
        ],
    ])->render();

    expect($view)
        ->toContain('Container Info')
        ->toContain('Container ID')
        ->toContain('Container Name')
        ->toContain('Image')
        ->toContain('Created At')
        ->toContain('Started At')
        ->toContain('IPv4')
        ->toContain('IPv6')
        ->toContain('web-service-uuid')
        ->toContain('ghcr.io/example/app:1.2.3')
        ->toContain('Copy container ID')
        ->toContain('Copy container name')
        ->toContain('Copy IPv4 address')
        ->toContain('Copy IPv6 address')
        ->not->toContain('Copy image')
        ->not->toContain('Copy created at')
        ->not->toContain('Copy started at');
});

it('renders the service detail page with the container info slice', function () {
    $view = file_get_contents(resource_path('views/livewire/project/service/index.blade.php'));

    expect($view)->toContain('<x-container-info :container-info="$containerInfo" />');
});
