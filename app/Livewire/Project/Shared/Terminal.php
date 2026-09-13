<?php

namespace App\Livewire\Project\Shared;

use App\Helpers\SshMultiplexingHelper;
use App\Models\Node;
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

    private function checkShellAvailability(Server $server, string $container): bool
    {
        $escapedContainer = escapeshellarg($container);
        try {
            instant_remote_process([
                "docker exec {$escapedContainer} bash -c 'exit 0' 2>/dev/null || ".
                "docker exec {$escapedContainer} sh -c 'exit 0' 2>/dev/null",
            ], $server);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    #[On('send-terminal-command')]
    public function sendTerminalCommand(
        bool $isContainer,
        string $identifier,
        string $serverUuid,
        string $targetType = 'server',
    ): void {
        $this->authorize('canAccessTerminal');

        $server = match ($targetType) {
            'node' => $this->terminalNode($serverUuid, (bool) $isContainer),
            'server' => Server::ownedByCurrentTeam()->whereUuid($serverUuid)->firstOrFail(),
            default => abort(404),
        };
        $this->authorize('view', $server);

        if ($server instanceof Node && (! $server->is_reachable || ! $server->is_usable)) {
            abort(403, 'Terminal access is unavailable while this Node is not ready.');
        }
        if ($server instanceof Server && (! $server->isTerminalEnabled() || $server->isForceDisabled())) {
            abort(403, 'Terminal access is disabled on this server.');
        }

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

            // Check shell availability
            $this->hasShell = $this->checkShellAvailability($server, $identifier);
            if (! $this->hasShell) {
                return;
            }

            // Escape the identifier for shell usage
            $escapedIdentifier = escapeshellarg($identifier);
            $shellCommand = 'PATH=$PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin && '.
                            'if [ -f ~/.profile ]; then . ~/.profile; fi && '.
                            'if [ -n "$SHELL" ] && [ -x "$SHELL" ]; then exec $SHELL; else sh; fi';

            // Add sudo for non-root users to access Docker socket
            $dockerCommand = "docker exec -it {$escapedIdentifier} sh -c '{$shellCommand}'";
            if ($server->isNonRoot()) {
                $dockerCommand = "sudo {$dockerCommand}";
            }

            $command = SshMultiplexingHelper::generateSshCommand(
                $server,
                $dockerCommand,
                commandTimeout: (int) config('constants.terminal.command_timeout')
            );
        } else {
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

    private function terminalNode(string $uuid, bool $isContainer): Node
    {
        abort_if($isContainer, 404);
        abort_unless(isDev() && config('constants.sentinel.host_enabled', false), 404);

        return Node::query()
            ->where('team_id', currentTeam()->id)
            ->where('uuid', $uuid)
            ->firstOrFail();
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
