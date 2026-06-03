<?php

it('uses Reverb as the first-party broadcast server', function () {
    expect(file_get_contents(base_path('composer.json')))
        ->toContain('"laravel/reverb"')
        ->and(file_get_contents(config_path('broadcasting.php')))
        ->toContain("'default' => env('BROADCAST_CONNECTION', env('BROADCAST_DRIVER', 'reverb'))")
        ->toContain("'reverb' => [")
        ->toContain("'key' => env('PUSHER_APP_KEY', 'coolify')")
        ->toContain("'secret' => env('PUSHER_APP_SECRET', 'coolify')")
        ->toContain("'app_id' => env('PUSHER_APP_ID', 'coolify')")
        ->toContain("'host' => env('PUSHER_HOST', 'coolify')")
        ->toContain("'port' => env('PUSHER_BACKEND_PORT', 6001)")
        ->and(file_exists(config_path('reverb.php')))->toBeTrue();
});

it('runs Reverb and terminal websocket services inside the Coolify containers', function (string $dockerfile, string $dependencyService) {
    $dockerfileContents = file_get_contents(base_path($dockerfile));

    expect($dockerfileContents)
        ->toContain('nodejs')
        ->toContain('npm')
        ->toContain('COPY docker/coolify-terminal/package*.json /terminal/')
        ->toContain('COPY docker/coolify-terminal/terminal-server.js /terminal/terminal-server.js')
        ->toContain('COPY docker/coolify-terminal/terminal-utils.js /terminal/terminal-utils.js')
        ->toContain('npm ci --prefix /terminal')
        ->and(file_get_contents(base_path(dirname($dockerfile).'/etc/s6-overlay/s6-rc.d/reverb/run')))
        ->toContain('exec php artisan reverb:start --host=0.0.0.0 --port=${PUSHER_BACKEND_PORT:-6001}')
        ->and(file_get_contents(base_path(dirname($dockerfile).'/etc/s6-overlay/s6-rc.d/terminal-server/run')))
        ->toContain('exec node /terminal/terminal-server.js')
        ->and(file_exists(base_path(dirname($dockerfile)."/etc/s6-overlay/s6-rc.d/reverb/dependencies.d/{$dependencyService}")))->toBeTrue()
        ->and(file_exists(base_path(dirname($dockerfile)."/etc/s6-overlay/s6-rc.d/terminal-server/dependencies.d/{$dependencyService}")))->toBeTrue()
        ->and(file_exists(base_path(dirname($dockerfile).'/etc/s6-overlay/s6-rc.d/user/contents.d/reverb')))->toBeTrue()
        ->and(file_exists(base_path(dirname($dockerfile).'/etc/s6-overlay/s6-rc.d/user/contents.d/terminal-server')))->toBeTrue();
})->with([
    'production image' => ['docker/production/Dockerfile', 'init-script'],
    'development image' => ['docker/development/Dockerfile', 'init-setup'],
]);

it('removes the dedicated realtime service from bundled compose files', function (string $composeFile, bool $hasRuntimeEnvironment) {
    $composeContents = file_get_contents(base_path($composeFile));

    expect($composeContents)
        ->not->toContain('coolify-realtime')
        ->not->toContain('soketi:')
        ->not->toContain('SOKETI_DEFAULT_APP_ID')
        ->toContain('6001')
        ->toContain('6002')
        ->not->toContain('REVERB_');

    if ($hasRuntimeEnvironment) {
        expect($composeContents)->toContain('PUSHER_BACKEND_PORT');
    }
})->with([
    'base compose' => ['docker-compose.yml', false],
    'production compose' => ['docker-compose.prod.yml', true],
    'development compose' => ['docker-compose.dev.yml', true],
    'maxio development compose' => ['docker-compose-maxio.dev.yml', true],
    'windows compose' => ['docker-compose.windows.yml', true],
]);

it('keeps the internal Reverb listen port separate from the public Pusher port', function () {
    expect(file_get_contents(config_path('reverb.php')))
        ->toContain("'port' => env('PUSHER_BACKEND_PORT', 6001)")
        ->toContain("'port' => env('PUSHER_PORT', 6001)")
        ->and(file_get_contents(base_path('docker/production/etc/s6-overlay/s6-rc.d/reverb/run')))
        ->toContain('exec php artisan reverb:start --host=0.0.0.0 --port=${PUSHER_BACKEND_PORT:-6001}')
        ->and(file_get_contents(base_path('docker/development/etc/s6-overlay/s6-rc.d/reverb/run')))
        ->toContain('exec php artisan reverb:start --host=0.0.0.0 --port=${PUSHER_BACKEND_PORT:-6001}');
});

it('proxies Reverb and terminal websocket traffic to the Coolify app container', function () {
    $serverModel = file_get_contents(app_path('Models/Server.php'));

    expect($serverModel)
        ->toContain("'rule' => \"Host(`{\$host}`) && PathPrefix(`/app`)\"")
        ->toContain("'rule' => \"Host(`{\$host}`) && PathPrefix(`/apps`)\"")
        ->toContain("'rule' => \"Host(`{\$host}`) && PathPrefix(`/terminal/ws`)\"")
        ->toContain("'url' => 'http://coolify:6001'")
        ->toContain("'url' => 'http://coolify:6002'")
        ->toContain('reverse_proxy coolify:6001')
        ->toContain('reverse_proxy coolify:6002')
        ->not->toContain('http://coolify-realtime:6001')
        ->not->toContain('http://coolify-realtime:6002')
        ->not->toContain('reverse_proxy coolify-realtime');
});

it('uses Pusher environment keys for self-hosted Reverb compatibility', function () {
    $files = [
        '.env.production',
        '.env.windows-docker-desktop.example',
        'docker-compose.prod.yml',
        'docker-compose.dev.yml',
        'docker-compose-maxio.dev.yml',
        'docker-compose.windows.yml',
        'scripts/install.sh',
        'scripts/upgrade.sh',
        'other/nightly/.env.production',
        'other/nightly/docker-compose.prod.yml',
        'other/nightly/docker-compose.windows.yml',
        'other/nightly/install.sh',
        'other/nightly/upgrade.sh',
    ];

    foreach ($files as $file) {
        expect(file_get_contents(base_path($file)))
            ->toContain('PUSHER_')
            ->not->toContain('REVERB_');
    }
});

it('defaults the public Pusher websocket port to Reverb instead of the HTTP app port', function () {
    expect(file_get_contents(base_path('.env.production')))
        ->toContain('PUSHER_PORT=6001')
        ->toContain('PUSHER_BACKEND_PORT=6001')
        ->and(file_get_contents(base_path('.env.windows-docker-desktop.example')))
        ->toContain('PUSHER_PORT=6001')
        ->toContain('PUSHER_BACKEND_PORT=6001')
        ->and(file_get_contents(base_path('scripts/install.sh')))
        ->toContain('update_env_var "PUSHER_PORT" "6001"')
        ->toContain('update_env_var "PUSHER_BACKEND_PORT" "6001"')
        ->toContain('normalize_pusher_port')
        ->and(file_get_contents(base_path('scripts/upgrade.sh')))
        ->toContain('update_env_var "PUSHER_PORT" "6001"')
        ->toContain('update_env_var "PUSHER_BACKEND_PORT" "6001"')
        ->toContain('normalize_pusher_port');
});

it('stops publishing or preserving the obsolete realtime image', function () {
    expect(file_get_contents(config_path('constants.php')))
        ->not->toContain('realtime_version')
        ->not->toContain('realtime_image')
        ->and(file_get_contents(app_path('Actions/Server/CleanupDocker.php')))
        ->not->toContain('coolify-realtime')
        ->not->toContain('realtimeImage')
        ->and(file_get_contents(base_path('versions.json')))
        ->not->toContain('"realtime"')
        ->and(is_dir(base_path('docker/coolify-realtime')))->toBeFalse()
        ->and(file_exists(base_path('.github/workflows/coolify-realtime.yml')))->toBeFalse()
        ->and(file_exists(base_path('.github/workflows/coolify-realtime-next.yml')))->toBeFalse();
});
