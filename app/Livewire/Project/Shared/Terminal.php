<?php

namespace App\Livewire\Project\Shared;

use App\Helpers\SshMultiplexingHelper;
use App\Models\Server;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\On;
use Livewire\Component;

class Terminal extends Component
{
    use AuthorizesRequests;

    public bool $hasShell = true;

    public bool $isTerminalConnected = false;

    public bool $autoStart = false;

    public string $variant = 'default';

    /**
     * Whether the selected container can be attached to (its main process keeps stdin open).
     */
    public bool $attachAvailable = false;

    /**
     * Active terminal mode: 'shell' (docker exec) or 'attach' (docker attach).
     */
    public string $terminalMode = 'shell';

    // Remembered target so the mode switch can reconnect without re-selecting a container.
    public bool $currentIsContainer = false;

    public ?string $currentIdentifier = null;

    public ?string $currentServerUuid = null;

    private function checkShellAvailability(Server $server, string $container): bool
    {
        $escapedContainer = escapeshellarg($container);
        // Non-root SSH users need sudo to reach the Docker socket.
        $sudo = $server->isNonRoot() ? 'sudo ' : '';
        try {
            instant_remote_process([
                "{$sudo}docker exec {$escapedContainer} bash -c 'exit 0' 2>/dev/null || ".
                "{$sudo}docker exec {$escapedContainer} sh -c 'exit 0' 2>/dev/null",
            ], $server);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function containerKeepsStdinOpen(Server $server, string $container): bool
    {
        $escapedContainer = escapeshellarg($container);
        // Non-root SSH users need sudo to reach the Docker socket.
        $sudo = $server->isNonRoot() ? 'sudo ' : '';
        try {
            $output = instant_remote_process([
                "{$sudo}docker inspect --format '{{.Config.OpenStdin}}' {$escapedContainer} 2>/dev/null",
            ], $server, false);

            return is_string($output) && str_starts_with(trim($output), 'true');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Resolve the effective terminal mode. Falls back to shell whenever attach is not available.
     */
    public function resolveMode(?string $requestedMode): string
    {
        if ($requestedMode === 'attach') {
            return $this->attachAvailable ? 'attach' : 'shell';
        }

        if ($requestedMode === 'shell') {
            return 'shell';
        }

        return $this->attachAvailable ? 'attach' : 'shell';
    }

    /**
     * Build the docker command that runs on the remote server for the given mode.
     */
    public function buildDockerCommand(Server $server, string $identifier, string $mode): string
    {
        $escapedIdentifier = escapeshellarg($identifier);
        // Add sudo for non-root users to access the Docker socket.
        $sudo = $server->isNonRoot() ? 'sudo ' : '';

        if ($mode === 'attach') {
            $detachKeys = config('constants.terminal.detach_keys');
            $historyLines = (int) config('constants.terminal.console_history_lines');

            // Print recent output first so the console is not blank, then attach for live output.
            $primeHistory = $historyLines > 0
                ? "{$sudo}docker logs --tail {$historyLines} {$escapedIdentifier} 2>&1; "
                : '';

            // Attach to the container's main process. --detach-keys lets the user leave with
            // Ctrl-P, Ctrl-Q and --sig-proxy=false stops the client forwarding signals to the app.
            return "{$primeHistory}exec {$sudo}docker attach --detach-keys=\"{$detachKeys}\" --sig-proxy=false {$escapedIdentifier}";
        }

        $shellCommand = 'PATH=$PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin && '.
                        'if [ -f ~/.profile ]; then . ~/.profile; fi && '.
                        'if [ -n "$SHELL" ] && [ -x "$SHELL" ]; then exec $SHELL; else sh; fi';

        return "{$sudo}docker exec -it {$escapedIdentifier} sh -c '{$shellCommand}'";
    }

    #[On('send-terminal-command')]
    public function sendTerminalCommand($isContainer, $identifier, $serverUuid, ?string $requestedMode = null): void
    {
        $this->authorize('canAccessTerminal');

        $server = Server::ownedByCurrentTeam()->whereUuid($serverUuid)->firstOrFail();
        $this->authorize('view', $server);

        if (! $server->isTerminalEnabled() || $server->isForceDisabled()) {
            abort(403, 'Terminal access is disabled on this server.');
        }

        // Remember the target so the mode switch can reconnect without re-selecting.
        $this->currentIsContainer = (bool) $isContainer;
        $this->currentIdentifier = $identifier;
        $this->currentServerUuid = $serverUuid;

        if ($isContainer) {
            // Validate container identifier format (alphanumeric, dashes, and underscores only)
            if (! ValidationPatterns::isValidContainerName($identifier)) {
                throw new \InvalidArgumentException('Invalid container identifier format');
            }

            // Verify container exists and belongs to the user's team
            $status = getContainerStatus($server, $identifier);
            if ($status !== 'running') {
                return;
            }

            // Check shell availability and whether the container can be attached to.
            $this->hasShell = $this->checkShellAvailability($server, $identifier);
            $this->attachAvailable = $this->containerKeepsStdinOpen($server, $identifier);

            $mode = $this->resolveMode($requestedMode);
            $this->terminalMode = $mode;

            // Shell mode needs a shell; console (attach) mode does not.
            if ($mode === 'shell' && ! $this->hasShell) {
                return;
            }

            $dockerCommand = $this->buildDockerCommand($server, $identifier, $mode);

            $command = SshMultiplexingHelper::generateSshCommand(
                $server,
                $dockerCommand,
                commandTimeout: (int) config('constants.terminal.command_timeout')
            );
        } else {
            $this->attachAvailable = false;
            $this->terminalMode = 'shell';
            $shellCommand = 'PATH=$PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin && '.
                            'if [ -f ~/.profile ]; then . ~/.profile; fi && '.
                            'if [ -n "$SHELL" ] && [ -x "$SHELL" ]; then exec $SHELL; else sh; fi';
            $command = SshMultiplexingHelper::generateSshCommand(
                $server,
                $shellCommand,
                commandTimeout: (int) config('constants.terminal.command_timeout')
            );
        }
        // ssh command is sent back to frontend then to websocket
        // this is done because the websocket connection is not available here
        // a better solution would be to remove websocket on NodeJS and work with something like
        // 1. Laravel Pusher/Echo connection (not possible without a sdk)
        // 2. Ratchet / Revolt / ReactPHP / Event Loop (possible but hard to implement and huge dependencies)
        // 3. Just found out about this https://github.com/sirn-se/websocket-php, perhaps it can be used
        // 4. Follow-up discussions here:
        //     - https://github.com/coollabsio/coolify/issues/2298
        //     - https://github.com/coollabsio/coolify/discussions/3362
        $this->dispatch('send-back-command', $command);
    }

    /**
     * Switch between shell and console (attach) mode and reconnect to the current container.
     */
    #[On('set-terminal-mode')]
    public function setMode(string $mode): void
    {
        if (! in_array($mode, ['shell', 'attach'], true)) {
            return;
        }

        if (! $this->currentIsContainer || $this->currentIdentifier === null || $this->currentServerUuid === null) {
            return;
        }

        $this->sendTerminalCommand(true, $this->currentIdentifier, $this->currentServerUuid, $mode);
    }

    #[On('terminalConnected')]
    public function markTerminalConnected(): void
    {
        $this->isTerminalConnected = true;
    }

    #[On('terminalDisconnected')]
    public function markTerminalDisconnected(): void
    {
        $this->isTerminalConnected = false;
    }

    public function keepTerminalPageAlive(): void
    {
        $this->isTerminalConnected = true;
    }

    public function render()
    {
        return view('livewire.project.shared.terminal');
    }
}
