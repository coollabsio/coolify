<?php

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
