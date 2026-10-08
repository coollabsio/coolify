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
    $this->application = createBrowserApplication($stack, ['uuid' => 'app-terminal-picker']);
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

it('lists the lazily loaded containers when the user must choose one', function () {
    Process::fake(function ($process) {
        $command = (string) $process->command;

        if (str_contains($command, 'docker ps -a')) {
            return Process::result(output: collect(['app-terminal-picker-web', 'app-terminal-picker-worker'])
                ->map(fn (string $name) => json_encode(['ID' => md5($name), 'Names' => $name, 'State' => 'running', 'Labels' => '']))
                ->implode("\n"));
        }

        // The chosen container stops before the session starts, so no WebSocket session is needed.
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
        ->assertSee('Start a terminal session')
        ->assertSee('app-terminal-picker-web')
        ->assertSee('app-terminal-picker-worker')
        ->screenshot(filename: 'terminal-container-picker-list')
        ->click('[data-terminal-target-picker="launcher"] button:nth-of-type(2)')
        ->assertSee('The container is not running.')
        ->assertDontSee('Start a terminal session')
        ->assertSee('app-terminal-picker-worker · localhost');

    $page->screenshot(filename: 'terminal-container-picker-selected');
});
