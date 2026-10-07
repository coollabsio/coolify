<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');

    $stack = seedBrowserResourceStack();
    $stack['server']->settings->update(['is_terminal_enabled' => true]);
    $this->serverUuid = $stack['server']->uuid;
    Cache::flush();

    $this->terminalServer = null;
});

afterEach(function () {
    $this->terminalServer?->stop(0);
});

/**
 * Start the fake terminal WebSocket server and point the terminal client at it.
 */
function startFakeTerminalServer(string $mode): array
{
    $process = new Process(['node', base_path('tests/Fixtures/terminal-websocket-rejection-server.mjs'), $mode]);
    $process->start();
    $process->waitUntil(fn (string $type, string $output) => str_contains($output, "\n"));
    $port = (int) trim($process->getOutput());

    config()->set('constants.terminal.protocol', 'ws');
    config()->set('constants.terminal.host', '127.0.0.1');
    config()->set('constants.terminal.port', (string) $port);

    return [$process, $port];
}

function fakeTerminalServerUpgrades(int $port): int
{
    return json_decode(file_get_contents("http://127.0.0.1:{$port}/stats"), true)['upgrades'];
}

/**
 * The browser plugin only boots for tests whose closure calls `visit(` as a standalone expression.
 */
function openServerTerminal(mixed $loginPage, string $serverUuid): mixed
{
    return $loginPage
        ->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->assertSee('Dashboard')
        ->navigate("/server/{$serverUuid}/terminal");
}

it('shows an auth rejection instead of connecting forever and does not reconnect', function () {
    [$this->terminalServer, $port] = startFakeTerminalServer('close-on-upgrade');

    $loginPage = visit('/login');

    $page = openServerTerminal($loginPage, $this->serverUuid)
        ->assertSee('Terminal access was rejected. Reload the page and try again.')
        ->assertSee('Reload page')
        ->assertDontSee('connecting…');

    // Auth rejections must not start the exponential reconnect loop.
    $page->wait(4);
    expect(fakeTerminalServerUpgrades($port))->toBe(1);

    $page->assertSee('Terminal access was rejected. Reload the page and try again.')
        ->screenshot(filename: 'terminal-auth-rejected');
});

it('shows an auth rejection when the terminal token is rejected', function () {
    [$this->terminalServer, $port] = startFakeTerminalServer('reject-token');

    $loginPage = visit('/login');

    $page = openServerTerminal($loginPage, $this->serverUuid)
        ->assertSee('Terminal access was rejected. Reload the page and try again.')
        ->assertDontSee('connecting…');

    $page->wait(4);
    expect(fakeTerminalServerUpgrades($port))->toBe(1);

    $page->screenshot(filename: 'terminal-token-rejected');
});

it('shows a connection error when the terminal server is unreachable', function () {
    // Reserve a free port, then release it so nothing is listening there.
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    config()->set('constants.terminal.protocol', 'ws');
    config()->set('constants.terminal.host', '127.0.0.1');
    config()->set('constants.terminal.port', (string) $port);

    $loginPage = visit('/login');

    openServerTerminal($loginPage, $this->serverUuid)
        ->assertSee('Could not connect to the terminal server. Reload the page and try again.')
        ->assertDontSee('connecting…')
        ->screenshot(filename: 'terminal-connection-failed');
});
