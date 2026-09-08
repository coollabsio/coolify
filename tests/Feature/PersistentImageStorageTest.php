<?php

it('configures a dedicated private disk for persisted images', function () {
    expect(config('filesystems.disks.images'))
        ->toMatchArray([
            'driver' => 'local',
            'root' => storage_path('app/images'),
            'visibility' => 'private',
        ]);
});

it('persists images in stable and nightly production compose files', function (string $composeFile) {
    expect(file_get_contents(base_path($composeFile)))
        ->toContain('/data/coolify/images:/var/www/html/storage/app/images');
})->with([
    'stable' => 'docker-compose.prod.yml',
    'nightly' => 'other/nightly/docker-compose.prod.yml',
]);

it('persists images in the Windows compose file', function () {
    expect(file_get_contents(base_path('docker-compose.windows.yml')))
        ->toContain('./images:/var/www/html/storage/app/images');
});

it('creates the persistent image directory during installation and upgrades', function (string $script) {
    expect(file_get_contents(base_path($script)))
        ->toContain('/data/coolify/images');
})->with([
    'install' => 'scripts/install.sh',
    'upgrade' => 'scripts/upgrade.sh',
]);
