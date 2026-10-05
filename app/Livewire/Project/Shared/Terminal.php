<?php

namespace App\Livewire\Project\Shared;

use App\Models\Node;
use App\Models\Server;
use App\Services\TerminalSessionService;
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
        TerminalSessionService $terminalSessionService,
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
            abort(403, 'Terminal access is unavailable while this server is not ready.');
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
        }

        $token = $terminalSessionService->issue(auth()->user(), $server, $isContainer ? $identifier : null);
        $this->dispatch('send-terminal-token', $token);
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
