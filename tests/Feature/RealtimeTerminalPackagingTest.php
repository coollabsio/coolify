<?php

it('proxies realtime websocket traffic through the bundled nginx server', function (string $environment) {
    $nginxConfiguration = file_get_contents(base_path("docker/{$environment}/etc/nginx/site-opts.d/http.conf"));
    $locationFound = preg_match('/location \^~ \/app\/ \{(?<body>.*?)\n\}/s', $nginxConfiguration, $location);

    expect($locationFound)
        ->toBe(1)
        ->and($location['body'])
        ->toContain('proxy_pass http://127.0.0.1:6001;')
        ->toContain('proxy_http_version 1.1;')
        ->toContain('proxy_set_header Upgrade $http_upgrade;')
        ->toContain('proxy_set_header Connection "upgrade";');
})->with(['development', 'production']);

it('proxies terminal websocket traffic through the bundled nginx server', function (string $environment) {
    $nginxConfiguration = file_get_contents(base_path("docker/{$environment}/etc/nginx/site-opts.d/http.conf"));
    $locationFound = preg_match('/location = \/terminal\/ws \{(?<body>.*?)\n\}/s', $nginxConfiguration, $location);

    expect($locationFound)
        ->toBe(1)
        ->and($location['body'])
        ->toContain('proxy_pass http://127.0.0.1:6002;')
        ->toContain('proxy_http_version 1.1;')
        ->toContain('proxy_set_header Upgrade $http_upgrade;')
        ->toContain('proxy_set_header Connection "upgrade";')
        ->toContain('proxy_read_timeout 3600s;')
        ->toContain('proxy_send_timeout 3600s;');
})->with(['development', 'production']);

it('does not route realtime services directly from the generated external proxy configuration', function () {
    $serverConfiguration = file_get_contents(app_path('Models/Server.php'));

    foreach (['coolify-realtime', 'coolify'] as $host) {
        foreach ([6001, 6002] as $port) {
            expect($serverConfiguration)
                ->not->toContain("'url' => 'http://{$host}:{$port}'")
                ->not->toContain("reverse_proxy {$host}:{$port}");
        }
    }
});

it('uses the dashboard port for same-origin realtime and terminal websocket connections', function () {
    $helpers = file_get_contents(base_path('bootstrap/helpers/shared.php'));
    $terminalClient = file_get_contents(resource_path('js/terminal.js'));

    expect($helpers)
        ->toContain('return $url->getPort();')
        ->not->toContain("return '6001';")
        ->and($terminalClient)
        ->toContain("port: window.location.port ? `:\${window.location.port}` : ''")
        ->not->toContain('port: ":6002"');
});

it('proxies the reverb API through the bundled nginx server', function (string $environment) {
    $nginxConfiguration = file_get_contents(base_path("docker/{$environment}/etc/nginx/site-opts.d/http.conf"));
    $locationFound = preg_match('/location \^~ \/apps\/ \{(?<body>.*?)\n\}/s', $nginxConfiguration, $location);

    expect($locationFound)
        ->toBe(1)
        ->and($location['body'])
        ->toContain('proxy_pass http://127.0.0.1:6001;');
})->with(['development', 'production']);

it('copies the terminal utilities into the Coolify container images', function (string $dockerfile) {
    $dockerfile = file_get_contents(base_path($dockerfile));

    expect($dockerfile)->toContain('COPY docker/coolify-terminal/terminal-utils.js /terminal/terminal-utils.js');
})->with([
    'production image' => 'docker/production/Dockerfile',
    'development image' => 'docker/development/Dockerfile',
]);

it('mounts the realtime terminal utilities in local development compose files', function (string $composeFile) {
    $composeContents = file_get_contents(base_path($composeFile));

    expect($composeContents)->toContain('./docker/coolify-terminal/terminal-utils.js:/terminal/terminal-utils.js');
})->with([
    'default dev compose' => 'docker-compose.dev.yml',
    'maxio dev compose' => 'docker-compose-maxio.dev.yml',
]);

it('does not replay single-use terminal tokens after reconnect', function () {
    $terminalClient = file_get_contents(base_path('resources/js/terminal.js'));

    expect($terminalClient)
        ->toContain('terminalToken')
        ->toContain("this.\$wire.on('send-terminal-token', ([token]) =>")
        ->toContain('tokens are single-use and must never be replayed')
        ->not->toContain('lastSentCommand')
        ->not->toContain('Replaying last command after reconnect.');
});

it('reports terminal authentication rejections with readable WebSocket close codes', function () {
    $terminalServer = file_get_contents(base_path('docker/coolify-terminal/terminal-server.js'));
    $terminalUtils = file_get_contents(base_path('docker/coolify-terminal/terminal-utils.js'));

    expect($terminalUtils)
        ->toContain('AUTH_REJECTED: 4401')
        ->toContain('TOKEN_REJECTED: 4403')
        ->and($terminalServer)
        ->toContain("new WebSocketServer({ noServer: true, path: '/terminal/ws' })")
        ->toContain("server.on('upgrade', createTerminalUpgradeHandler({ wss, authenticate: verifyClient }))")
        ->toContain("rejectTerminalToken(userSession, 'Unauthorized: Invalid terminal token')")
        ->toContain("rejectTerminalToken(userSession, 'Unauthorized: Terminal token was rejected')")
        ->toContain("typeof token !== 'string' || !/^[a-zA-Z0-9]{64}$/.test(token)")
        ->toContain("response.status !== 200 || typeof response.data?.command !== 'string'")
        ->not->toContain('verifyClient: verifyClient')
        ->not->toContain('ws.close(401');
});
