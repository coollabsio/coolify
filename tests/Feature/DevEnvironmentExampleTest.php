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
    expect(config('coold.coolify_cli_bin'))->toBe('/usr/local/bin/coolify')
        ->and(config('coold.coold_version'))->toBe('nightly')
        ->and(config('coold.corrosion_version'))->toBe('v1.0.0')
        ->and(config('coold.dev_ssh_user'))->toBe('coolify')
        ->and(config('coold.dev_builder_capacity'))->toBe(2)
        ->and(config('coold.dev_builder_enabled'))->toBeTrue();
});

it('runs the v5 dev Lima seeder with the normal development database seeder', function () {
    $databaseSeeder = file_get_contents(database_path('seeders/DatabaseSeeder.php'));
    $developmentSeederBlock = str($databaseSeeder)->after("if (in_array(config('app.env'), ['local', 'development', 'dev'], true)) {")->before('        }')->toString();

    expect($developmentSeederBlock)->toContain('DevelopmentRailpackExamplesSeeder::class')
        ->and($developmentSeederBlock)->toContain('V5DevLimaSeeder::class');
});
