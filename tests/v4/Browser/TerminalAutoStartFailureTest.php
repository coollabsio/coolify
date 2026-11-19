<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    config()->set('constants.ssh.mux_enabled', false);

    $stack = seedBrowserResourceStack();
    $stack['server']->settings->update(['is_terminal_enabled' => true]);
    $this->application = createBrowserApplication($stack, ['uuid' => 'app-terminal-autostart']);
    $this->terminalPath = "/project/{$stack['project']->uuid}/environment/{$stack['environment']->uuid}/application/{$this->application->uuid}/terminal";
    Cache::flush();

    // An open WebSocket that accepts the connection and waits (it only reacts to a client frame).
    $this->terminalServer = new SymfonyProcess(['node', base_path('tests/Fixtures/terminal-websocket-rejection-server.mjs'), 'reject-token']);
    $this->terminalServer->start();
    $this->terminalServer->waitUntil(fn (string $type, string $output) => str_contains($output, "\n"));
    config()->set('constants.terminal.protocol', 'ws');
    config()->set('constants.terminal.host', '127.0.0.1');
    config()->set('constants.terminal.port', trim($this->terminalServer->getOutput()));
});

afterEach(function () {
    $this->terminalServer?->stop(0);
});

it('shows why an auto-started container terminal cannot start instead of connecting forever', function () {
    // One running container makes the page auto-connect; it stops before the token is issued.
    Process::fake(function ($process) {
        $command = (string) $process->command;

        if (str_contains($command, 'docker ps -a')) {
            return Process::result(output: json_encode(['Names' => 'app-terminal-autostart-1', 'State' => 'running', 'Labels' => '']));
        }

        if (str_contains($command, 'docker inspect')) {
            return Process::result(output: json_encode(['State' => ['Status' => 'exited']]));
        }

        return Process::result();
    });

    $page = visit('/login')
        ->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->assertSee('Dashboard')
        ->navigate($this->terminalPath)
        ->assertSee('The container is not running.')
        ->assertSee('Reload page')
        ->assertDontSee('connecting…');

    $page->screenshot(filename: 'terminal-auto-start-container-not-running');
});
