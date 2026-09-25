<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestFacade;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

it('uses Reverb as the first-party broadcast server', function () {
    expect(file_get_contents(base_path('composer.json')))
        ->toContain('"laravel/reverb"')
        ->and(file_get_contents(config_path('broadcasting.php')))
        ->toContain("'default' => env('BROADCAST_CONNECTION', env('BROADCAST_DRIVER', 'reverb'))")
        ->toContain("'reverb' => [")
        ->toContain("'key' => env('PUSHER_APP_KEY', 'coolify')")
        ->toContain("'secret' => env('PUSHER_APP_SECRET', 'coolify')")
        ->toContain("'app_id' => env('PUSHER_APP_ID', 'coolify')")
        ->toContain("'host' => \$backendHost")
        ->toContain("'port' => env('PUSHER_BACKEND_PORT', 6001)")
        ->toContain("'scheme' => env('PUSHER_BACKEND_SCHEME', 'http')")
        ->and(file_exists(config_path('reverb.php')))->toBeTrue();
});

it('does not send server-side broadcasts to the browser-facing Pusher host', function () {
    $reverbConnection = Str::between(file_get_contents(config_path('broadcasting.php')), "'reverb' => [", "'pusher' => [");

    expect($reverbConnection)
        ->not->toContain('PUSHER_HOST')
        ->not->toContain("env('PUSHER_SCHEME'");
});

it('keeps working with environment variables from installs that used the realtime container', function (array $environment, string $connection, string $expectedHost) {
    $previous = [];

    foreach ($environment as $key => $value) {
        $previous[$key] = $_SERVER[$key] ?? null;
        $_SERVER[$key] = $value;
    }

    try {
        $broadcasting = require config_path('broadcasting.php');
    } finally {
        foreach ($previous as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
    }

    $options = $broadcasting['connections'][$connection]['options'];

    expect($options['host'])->toBe($expectedHost)
        ->and($options['port'])->toBe(6001)
        ->and($options['scheme'])->toBe('http')
        ->and($options['useTLS'])->toBeFalse();
})->with([
    'legacy realtime backend host' => [['PUSHER_BACKEND_HOST' => 'coolify-realtime'], 'reverb', '127.0.0.1'],
    'legacy pusher driver' => [['BROADCAST_DRIVER' => 'pusher', 'PUSHER_BACKEND_HOST' => 'coolify-realtime'], 'pusher', '127.0.0.1'],
    'public browser host and scheme' => [['PUSHER_HOST' => 'coolify.example.com', 'PUSHER_SCHEME' => 'https', 'PUSHER_PORT' => '443'], 'reverb', '127.0.0.1'],
    'custom backend host' => [['PUSHER_BACKEND_HOST' => 'coolify'], 'reverb', 'coolify'],
]);

it('rewrites the legacy realtime backend host during upgrades', function (string $script) {
    expect(file_get_contents(base_path($script)))
        ->toContain('if grep -q \'^PUSHER_BACKEND_HOST=coolify-realtime$\' "$ENV_FILE"; then')
        ->toContain('set_env_var "PUSHER_BACKEND_HOST" "127.0.0.1"');
})->with([
    'upgrade script' => ['scripts/upgrade.sh'],
    'nightly upgrade script' => ['other/nightly/upgrade.sh'],
]);

it('includes Reverb but not the terminal server in the Coolify container healthcheck', function (string $composeFile) {
    $composeContents = file_get_contents(base_path($composeFile));

    expect($composeContents)
        ->toContain('/api/health && curl --fail http://')
        ->toContain(':${PUSHER_BACKEND_PORT:-6001}/up || exit 1')
        ->not->toContain('6002/ready');
})->with([
    'production compose' => ['docker-compose.prod.yml'],
    'nightly production compose' => ['other/nightly/docker-compose.prod.yml'],
    'windows compose' => ['docker-compose.windows.yml'],
    'nightly windows compose' => ['other/nightly/docker-compose.windows.yml'],
]);

it('removes the legacy realtime container during upgrades', function (string $script) {
    expect(file_get_contents(base_path($script)))
        ->toContain('for container in coolify coolify-db coolify-redis coolify-realtime; do');
})->with([
    'upgrade script' => ['scripts/upgrade.sh'],
    'nightly upgrade script' => ['other/nightly/upgrade.sh'],
]);

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
        ->not->toContain('PUSHER_ENABLED')
        ->not->toContain('Reverb is disabled')
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

    // Production keeps "coolify-realtime" only as a network alias of the coolify container.
    expect($composeContents)
        ->not->toContain('container_name: coolify-realtime')
        ->not->toContain('coolify-realtime:')
        ->not->toContain('soketi:')
        ->not->toContain('SOKETI_DEFAULT_APP_ID')
        ->toContain('6001')
        ->toContain('6002')
        ->not->toMatch('/REVERB_(?!PORT\b)/');

    if ($hasRuntimeEnvironment) {
        expect($composeContents)->toContain('PUSHER_BACKEND_PORT');
    }
})->with([
    'base compose' => ['docker-compose.yml', false],
    'production compose' => ['docker-compose.prod.yml', true],
    'development compose' => ['docker-compose.dev.yml', true],
    'maxio development compose' => ['docker-compose-maxio.dev.yml', true],
    'multi-instance development compose' => ['docker-compose.dev-multi.yml', true],
    'windows compose' => ['docker-compose.windows.yml', true],
]);

it('keeps the internal Reverb listen port separate from the public Pusher port', function () {
    expect(file_get_contents(config_path('reverb.php')))
        ->toContain("'port' => env('PUSHER_BACKEND_PORT', 6001)")
        ->not->toContain('PUSHER_PORT')
        ->not->toContain("env('PUSHER_HOST', 'coolify')")
        ->and(file_get_contents(base_path('docker/production/etc/s6-overlay/s6-rc.d/reverb/run')))
        ->toContain('exec php artisan reverb:start --host=0.0.0.0 --port=${PUSHER_BACKEND_PORT:-6001}')
        ->and(file_get_contents(base_path('docker/development/etc/s6-overlay/s6-rc.d/reverb/run')))
        ->toContain('exec php artisan reverb:start --host=0.0.0.0 --port=${PUSHER_BACKEND_PORT:-6001}');
});

it('uses the browser host and page TLS mode for development Reverb connections', function () {
    $baseLayout = file_get_contents(resource_path('views/layouts/base.blade.php'));

    expect(file_get_contents(base_path('docker-compose.dev.yml')))
        ->toContain('PUSHER_HOST: "${PUSHER_HOST:-}"')
        ->and(file_get_contents(base_path('docker-compose-maxio.dev.yml')))
        ->toContain('PUSHER_HOST: "${PUSHER_HOST:-}"')
        ->and(file_get_contents(base_path('docker-compose.dev-multi.yml')))
        ->toContain('PUSHER_HOST: "${PUSHER_HOST:-}"')
        ->and($baseLayout)
        ->toContain("forceTLS: window.location.protocol === 'https:'")
        ->not->toContain('forceTLS: false');
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
            ->not->toMatch('/REVERB_(?!PORT\b)/');
    }
});

it('keeps the public websocket port adaptive and configures only the internal Reverb port', function () {
    expect(file_get_contents(base_path('.env.production')))
        ->not->toContain('PUSHER_PORT=')
        ->not->toContain('PUSHER_BACKEND_PORT=')
        ->and(file_get_contents(base_path('.env.windows-docker-desktop.example')))
        ->not->toContain('PUSHER_PORT=')
        ->toContain('PUSHER_BACKEND_PORT=6001')
        ->and(file_get_contents(base_path('scripts/install.sh')))
        ->not->toContain('update_env_var "PUSHER_PORT"')
        ->toContain('update_env_var "PUSHER_BACKEND_PORT" "6001"')
        ->and(file_get_contents(base_path('scripts/upgrade.sh')))
        ->not->toContain('update_env_var "PUSHER_PORT"')
        ->toContain('update_env_var "PUSHER_BACKEND_PORT" "6001"');
});

it('does not use the browser websocket port as the Docker host port', function (string $composeFile) {
    expect(file_get_contents(base_path($composeFile)))
        ->toContain('"${REVERB_PORT:-${SOKETI_PORT:-6001}}:6001"')
        ->not->toContain('"${PUSHER_PORT:-6001}:6001"');
})->with([
    'production compose' => ['docker-compose.prod.yml'],
    'nightly production compose' => ['other/nightly/docker-compose.prod.yml'],
    'windows compose' => ['docker-compose.windows.yml'],
    'nightly windows compose' => ['other/nightly/docker-compose.windows.yml'],
]);

it('preserves existing public websocket port overrides during install and upgrade', function (string $script) {
    $contents = file_get_contents(base_path($script));

    expect($contents)
        ->not->toContain('normalize_pusher_port')
        ->not->toMatch('/(?:set|update)_env_var "PUSHER_PORT"/');
})->with([
    'install script' => ['scripts/install.sh'],
    'upgrade script' => ['scripts/upgrade.sh'],
    'nightly install script' => ['other/nightly/install.sh'],
    'nightly upgrade script' => ['other/nightly/upgrade.sh'],
]);

it('stops publishing or preserving the obsolete realtime image', function () {
    $productionInstallScript = file_get_contents(base_path('scripts/install.sh'));
    $nightlyInstallScript = file_get_contents(base_path('other/nightly/install.sh'));

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
        ->and(file_exists(base_path('.github/workflows/coolify-realtime-next.yml')))->toBeFalse()
        ->and(file_get_contents(base_path('.github/workflows/coolify-next-build.yml')))->not->toContain('coolify-realtime')
        ->and($productionInstallScript)->not->toContain('LATEST_REALTIME_VERSION')
        ->not->toContain('| Realtime')
        ->and($nightlyInstallScript)->not->toContain('LATEST_REALTIME_VERSION')
        ->not->toContain('| Realtime');
});

it('keeps 4.3.23 realtime setups working with the production compose files', function (string $composeFile) {
    $coolify = Yaml::parseFile(base_path($composeFile))['services']['coolify'];

    // The browser port comes only from .env (for example PUSHER_PORT=443 for Cloudflare Tunnel).
    expect(collect($coolify['environment'])->filter(fn (string $entry) => Str::startsWith($entry, 'PUSHER_PORT')))->toBeEmpty()
        ->and($coolify['networks']['coolify']['aliases'])->toContain('coolify-realtime')
        ->and($coolify['ports'])->toContain('6002:6002')
        ->and(implode("\n", [...$coolify['ports'], ...$coolify['expose']]))->not->toContain('TERMINAL_PORT');
})->with([
    'production compose' => ['docker-compose.prod.yml'],
    'nightly production compose' => ['other/nightly/docker-compose.prod.yml'],
]);

it('keeps TERMINAL_PORT browser-only in the Windows compose files', function (string $composeFile) {
    $coolify = Yaml::parseFile(base_path($composeFile))['services']['coolify'];

    expect($coolify['ports'])->toContain('6002:6002')
        ->and(implode("\n", [...$coolify['ports'], ...$coolify['expose']]))->not->toContain('TERMINAL_PORT');
})->with([
    'windows compose' => ['docker-compose.windows.yml'],
    'nightly windows compose' => ['other/nightly/docker-compose.windows.yml'],
]);

it('resolves the browser websocket port like 4.3.23', function (string $pageUrl, ?string $pusherPort, ?string $expectedPort) {
    config()->set('constants.pusher.port', $pusherPort);
    RequestFacade::swap(Request::create($pageUrl));

    expect(getRealtime())->toBe($expectedPort);
})->with([
    'https domain through the Coolify proxy' => ['https://coolify.example.com/', null, null],
    'https domain with an empty PUSHER_PORT' => ['https://coolify.example.com/', '', null],
    'direct IP and port access' => ['http://203.0.113.10:8000/', null, '6001'],
    'Cloudflare Tunnel guide' => ['https://coolify.example.com/', '443', '443'],
]);

it('uses current Reverb and terminal names in development tooling', function () {
    expect(file_get_contents(base_path('scripts/dev')))
        ->toContain('FORWARD_PUSHER_PORT')
        ->toContain('FORWARD_TERMINAL_PORT')
        ->not->toContain('SOKETI');
});
