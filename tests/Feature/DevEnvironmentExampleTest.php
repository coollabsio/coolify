<?php

it('does not include coold dev tooling defaults in the development env example', function (string $variable) {
    $developmentExample = file_get_contents(base_path('.env.development.example'));

    expect($developmentExample)->not->toContain($variable.'=');
})->with([
    'coold package version default' => 'COOLIFY_COOLD_VERSION',
    'flux package version default' => 'COOLIFY_FLUX_VERSION',
    'corrosion package version default' => 'COOLIFY_CORROSION_VERSION',
    'coold VM count default' => 'COOLIFY_COOLD_VM_COUNT',
    'coold VM flux URL default' => 'COOLIFY_COOLD_VM_FLUX_URL',
    'coold VM WireGuard IP 1 default' => 'COOLIFY_COOLD_VM_WG_IP_1',
    'coold VM WireGuard IP 2 default' => 'COOLIFY_COOLD_VM_WG_IP_2',
    'coold VM WireGuard port 1 default' => 'COOLIFY_COOLD_VM_WG_PORT_1',
    'coold VM WireGuard port 2 default' => 'COOLIFY_COOLD_VM_WG_PORT_2',
    'coold VM builder capacity default' => 'COOLIFY_COOLD_VM_BUILDER_CAPACITY',
    'coold VM enabled default' => 'COOLIFY_COOLD_VM_ENABLED',
    'coold VM stop on down default' => 'COOLIFY_COOLD_VM_STOP_ON_DOWN',
    'dev follow logs default' => 'COOLIFY_DEV_FOLLOW_LOGS',
]);

it('defaults coold dev VM settings in Laravel config', function () {
    expect(config('coold.dev_host_count'))->toBe(2)
        ->and(config('coold.dev_host_id'))->toBe('coolify-coold-dev')
        ->and(config('coold.dev_host_id_2'))->toBe('coolify-coold-dev-2')
        ->and(config('coold.dev_wireguard_ip_1'))->toBe('100.64.0.10')
        ->and(config('coold.dev_wireguard_ip_2'))->toBe('100.64.0.11')
        ->and(config('coold.dev_builder_capacity'))->toBe(2)
        ->and(config('coold.dev_builder_enabled'))->toBeTrue();
});
