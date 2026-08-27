<?php

use App\Livewire\Project\Shared\Terminal;
use App\Models\Server;

function consoleModeServer(bool $nonRoot): Server
{
    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('isNonRoot')->andReturn($nonRoot);

    return $server;
}

it('builds an interactive attach command with safe detach keys for console mode', function () {
    $command = (new Terminal)->buildDockerCommand(consoleModeServer(false), 'minecraft-abc123', 'attach');

    expect($command)
        // Primes the console with recent logs, then attaches for live output.
        ->toContain("docker logs --tail 200 'minecraft-abc123' 2>&1; ")
        ->toContain('exec docker attach --detach-keys="ctrl-p,ctrl-q" --sig-proxy=false \'minecraft-abc123\'');
});

it('prefixes sudo for non-root servers in console mode', function () {
    $command = (new Terminal)->buildDockerCommand(consoleModeServer(true), 'minecraft-abc123', 'attach');

    expect($command)
        ->toStartWith('sudo docker logs --tail 200 ')
        ->toContain('exec sudo docker attach --detach-keys="ctrl-p,ctrl-q" --sig-proxy=false ');
});

it('keeps the interactive shell command for shell mode', function () {
    $command = (new Terminal)->buildDockerCommand(consoleModeServer(false), 'web-1', 'shell');

    expect($command)
        ->toContain("docker exec -it 'web-1' sh -c")
        ->not->toContain('docker attach');
});

it('defaults to console mode when the container keeps stdin open', function () {
    $terminal = new Terminal;
    $terminal->attachAvailable = true;

    expect($terminal->resolveMode(null))->toBe('attach');
});

it('defaults to shell mode when the container has no open stdin', function () {
    $terminal = new Terminal;
    $terminal->attachAvailable = false;

    expect($terminal->resolveMode(null))->toBe('shell');
});

it('never selects console mode when attach is unavailable even if requested', function () {
    $terminal = new Terminal;
    $terminal->attachAvailable = false;

    expect($terminal->resolveMode('attach'))->toBe('shell');
});

it('renders a line-oriented command box for console mode instead of a raw terminal', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/terminal.blade.php'));

    expect($view)
        ->toContain('terminal-console-input')
        ->toContain('submitConsoleCommand()')
        ->toContain('x-ref="consoleInput"')
        // The mobile control toolbar (which can send Ctrl-C) is hidden in console mode.
        ->toContain("terminalActive && \$wire.terminalMode !== 'attach'")
        // The earlier guardrail UI is gone in favour of the command box.
        ->not->toContain('detachTerminal()')
        ->not->toContain('Ctrl-C can stop the app');
});

it('keeps the console terminal read-only and sends whole command lines', function () {
    $client = file_get_contents(resource_path('js/terminal.js'));

    expect($client)
        ->toContain('isConsoleMode()')
        // Keystrokes are not forwarded while attached; commands carry a trailing newline.
        ->toContain('message: `${command}\n`')
        ->toContain('this.term.options.disableStdin = consoleMode');
});

it('places the shell/console selector beside the theme selector using shared styling', function () {
    $selector = file_get_contents(resource_path('views/components/terminal/mode-selector.blade.php'));

    expect($selector)
        // Reuses the shared trigger + menu styling from the theme/renderer selectors.
        ->toContain('terminal-theme-trigger')
        ->toContain('console-theme-selector')
        // Only shown when the container is interactive; drives the parent Alpine state.
        ->toContain('x-show="attachAvailable"')
        ->toContain('setTerminalMode(')
        ->toContain("'key' => 'shell'")
        ->toContain("'key' => 'attach'");

    foreach (['project/shared/execute-container-command', 'terminal/index'] as $view) {
        $blade = file_get_contents(resource_path("views/livewire/{$view}.blade.php"));

        expect($blade)
            // Grouped with the theme selector so extra header options do not break the layout.
            ->toContain('<div class="ml-auto flex items-center gap-2">')
            ->toContain('<x-terminal.mode-selector />')
            ->toContain('terminal-mode-updated.window')
            ->toContain("Livewire.dispatch('set-terminal-mode', { mode });");
    }
});

it('lets the header selector switch modes through a livewire listener', function () {
    $component = file_get_contents(app_path('Livewire/Project/Shared/Terminal.php'));

    expect($component)->toContain("#[On('set-terminal-mode')]");
});
