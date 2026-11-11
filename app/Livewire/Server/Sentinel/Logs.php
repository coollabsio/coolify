<?php

namespace App\Livewire\Server\Sentinel;

use App\Enums\SentinelRuntime;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Component;

class Logs extends Component
{
    use AuthorizesRequests;

    public ?Server $server = null;

    public array $parameters = [];

    /** Recent journal lines of `sentinel.service`, for a server where Sentinel runs on the host. */
    public ?string $journalLogs = null;

    public const JOURNAL_LINES = 200;

    public function mount(): void
    {
        $this->parameters = get_route_parameters();
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid(request()->server_uuid)->firstOrFail();
        } catch (\Throwable $e) {
            handleError($e, $this);

            return;
        }

        $this->authorize('viewSentinel', $this->server);
    }

    /**
     * Sentinel output can contain secrets, so it needs the same `update` permission as the container logs.
     */
    public function loadJournalLogs(): void
    {
        try {
            $this->authorize('update', $this->server);
            $server = $this->server;
            if (! $this->isHostRuntime() || ! $server->isFunctional()) {
                $this->journalLogs = null;

                return;
            }

            $this->journalLogs = (string) instant_remote_process(
                ['journalctl -u sentinel.service --no-pager -o short-iso -n '.self::JOURNAL_LINES],
                $server,
                throwError: false,
            );
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function isHostRuntime(): bool
    {
        return $this->server?->settings?->sentinel_runtime === SentinelRuntime::Host;
    }

    public function render(): View
    {
        return view('livewire.server.sentinel.logs');
    }
}
