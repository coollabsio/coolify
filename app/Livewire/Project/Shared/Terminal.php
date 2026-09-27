<?php

namespace App\Livewire\Project\Shared;

use App\Models\Server;
use App\Services\TerminalSessionService;
use App\Support\ValidationPatterns;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Terminal extends Component
{
    use AuthorizesRequests;

    /** Browser event: no terminal token was issued. Payload: `message`. */
    public const SESSION_FAILED_EVENT = 'terminal-session-failed';

    /** Browser event: the page did not auto-select a target, so stop the auto-start wait. */
    public const AUTO_START_CANCELLED_EVENT = 'terminal-auto-start-cancelled';

    /** Same text for denied and unknown targets, so other teams' resources stay hidden. */
    public const NOT_ALLOWED_MESSAGE = 'You are not allowed to open a terminal for this resource.';

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
        } catch (Throwable) {
            return false;
        }
    }

    #[On('send-terminal-command')]
    public function sendTerminalCommand($isContainer, $identifier, $serverUuid, TerminalSessionService $terminalSessionService): void
    {
        try {
            $this->authorize('canAccessTerminal');

            $server = Server::ownedByCurrentTeam()->whereUuid($serverUuid)->firstOrFail();
            $this->authorize('view', $server);
        } catch (AuthorizationException|ModelNotFoundException) {
            $this->failTerminalSession(self::NOT_ALLOWED_MESSAGE);

            return;
        }

        if (! $server->isTerminalEnabled() || $server->isForceDisabled()) {
            $this->failTerminalSession('Terminal access is disabled on this server.');

            return;
        }

        if ($isContainer) {
            // Validate container identifier format (alphanumeric, dashes, and underscores only)
            if (! is_string($identifier) || ! ValidationPatterns::isValidContainerName($identifier)) {
                $this->failTerminalSession('The container name is not valid.');

                return;
            }

            // Verify container exists and belongs to the user's team
            $status = getContainerStatus($server, $identifier);
            if ($status !== 'running') {
                $this->failTerminalSession('The container is not running.');

                return;
            }

            // Check shell availability
            $this->hasShell = $this->checkShellAvailability($server, $identifier);
            if (! $this->hasShell) {
                $this->failTerminalSession('No shell is available in this container.');

                return;
            }
        }

        $token = $terminalSessionService->issue(auth()->user(), $server, $isContainer ? $identifier : null);
        $this->dispatch('send-terminal-token', $token);
    }

    /**
     * Message for an exception on the terminal start path. Denied and missing
     * resources get the same text, so the message does not reveal other teams' data.
     */
    public static function sessionFailureMessage(Throwable $exception): string
    {
        $isDenied = $exception instanceof AuthorizationException
            || $exception instanceof ModelNotFoundException
            || ($exception instanceof HttpExceptionInterface && in_array($exception->getStatusCode(), [403, 404], true));

        if ($isDenied) {
            return self::NOT_ALLOWED_MESSAGE;
        }

        return $exception->getMessage() ?: 'Could not start the terminal session.';
    }

    private function failTerminalSession(string $message): void
    {
        $this->dispatch(self::SESSION_FAILED_EVENT, message: $message)->self();
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
