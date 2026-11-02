<?php

use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    config()->set('app.maintenance.store', 'array');
    config()->set('constants.ssh.mux_enabled', false);
    $this->terminalServer = null;
});

afterEach(function () {
    $this->terminalServer?->stop(0);
});

it('keeps the active input focused through all PTY startup callbacks', function (bool $attach) {
    $stack = seedBrowserResourceStack();
    $stack['server']->settings->update(['is_terminal_enabled' => true]);
    Cache::flush();

    Process::fake(function ($process) use ($attach) {
        $command = (string) $process->command;
        if (str_contains($command, 'docker ps -a')) {
            return Process::result(output: json_encode(['Names' => 'app-terminal-focus-1', 'State' => 'running', 'Labels' => '']));
        }
        if (str_contains($command, 'Config.OpenStdin')) {
            return Process::result(output: $attach ? 'true' : 'false');
        }
        if (str_contains($command, 'docker inspect')) {
            return Process::result(output: json_encode(['State' => ['Status' => 'running']]));
        }

        return Process::result();
    });

    $this->terminalServer = new SymfonyProcess(['node', base_path('tests/Fixtures/terminal-websocket-rejection-server.mjs'), 'ready']);
    $this->terminalServer->start();
    $this->terminalServer->waitUntil(fn (string $type, string $output) => str_contains($output, "\n"));
    config()->set('constants.terminal.protocol', 'ws');
    config()->set('constants.terminal.host', '127.0.0.1');
    config()->set('constants.terminal.port', trim($this->terminalServer->getOutput()));

    $page = visit('/login')
        ->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->assertSee('Dashboard')
        ->navigate('/terminal')
        ->click('[data-terminal-target-picker="page"] button:has-text("app-terminal-focus-1")')
        ->assertVisible('.xterm-viewport')
        ->wait(0.75);

    $selector = $attach ? '[x-ref="consoleInput"]' : '.xterm-helper-textarea';
    expect($page->script("document.activeElement.matches('$selector')"))->toBeTrue();

    // Exercise the same ready handler on reconnect. Blur after the 100 ms callback
    // so only the 500 ms callback can restore focus, not the initial focus call.
    $focusedInputs = $page->script(<<<'JS'
        async () => {
            const terminal = Alpine.$data(document.getElementById('terminal-container'));
            const focusedInput = () => document.activeElement.matches('[x-ref="consoleInput"]') ? 'console' :
                document.activeElement.matches('.xterm-helper-textarea') ? 'shell' : 'other';
            terminal.handleSocketMessage({ data: 'pty-ready' });
            const immediate = focusedInput();
            await new Promise(resolve => setTimeout(resolve, 150));
            const after100ms = focusedInput();
            document.activeElement.blur();
            await new Promise(resolve => setTimeout(resolve, 450));
            return [immediate, after100ms, focusedInput()];
        }
        JS);

    $expectedInput = $attach ? 'console' : 'shell';
    expect($focusedInputs)->toBe([$expectedInput, $expectedInput, $expectedInput]);
    $page->assertNoJavaScriptErrors()
        ->screenshot(filename: $attach ? 'terminal-console-startup-focus' : 'terminal-shell-startup-focus');
})->with(['console' => true, 'shell' => false]);
