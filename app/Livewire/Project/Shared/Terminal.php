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
            ], $server, no_sudo: true);

            return true;
        } catch (Throwable) {
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
            ], $server, false, no_sudo: true);

            return is_string($output) && str_starts_with(trim($output), 'true');
        } catch (Throwable) {
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

    #[On('send-terminal-command')]
    public function sendTerminalCommand($isContainer, $identifier, $serverUuid, ?string $requestedMode = null): void
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

        // Remember the target so the mode switch can reconnect without re-selecting.
        $this->currentIsContainer = (bool) $isContainer;
        $this->currentIdentifier = $identifier;
        $this->currentServerUuid = $serverUuid;

        if ($isContainer) {
            // Validate container identifier format (alphanumeric, dashes, and underscores only)
            if (! is_string($identifier) || ! ValidationPatterns::isValidContainerName($identifier)) {
                $this->failTerminalSession('The container name is not valid.');

                return;
            }

            // Verify the container is running on the authorized server
            $status = getContainerStatus($server, $identifier);
            if ($status !== 'running') {
                $this->failTerminalSession('The container is not running.');

                return;
            }

            // Check shell availability and whether the container can be attached to.
            $this->hasShell = $this->checkShellAvailability($server, $identifier);
            $this->attachAvailable = $this->containerKeepsStdinOpen($server, $identifier);

            $mode = $this->resolveMode($requestedMode);
            $this->terminalMode = $mode;

            // Shell mode needs a shell; console (attach) mode does not.
            if ($mode === 'shell' && ! $this->hasShell) {
                $this->failTerminalSession('No shell is available in this container.');

                return;
            }
        } else {
            $this->attachAvailable = false;
            $mode = $this->terminalMode = 'shell';
        }

        $token = app(TerminalSessionService::class)->issue(auth()->user(), $server, $isContainer ? $identifier : null, $mode);
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
