<?php

namespace App\Jobs;

use App\Enums\V5\ServerStatus;
use App\Models\V5\Application as V5Application;
use App\Models\V5\ContainerStatus;
use App\Models\V5\Server as V5Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled fan-out for the v5 reconciliation loop: dispatches one
 * V5ReconcileServerStateJob per managed server and prunes container status
 * rows that no webhook has refreshed within the TTL.
 */
class V5ReconcileServersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CONTAINER_STATUS_TTL_HOURS = 24;

    public int $tries = 1;

    public int $timeout = 60;

    /**
     * Reconcile runs on its own queue so the 5-minute fleet fan-out can never
     * starve user-triggered deploys and bootstraps sharing the default queue.
     * Set via onQueue() rather than a `$queue` property redeclaration, which
     * the Queueable trait already defines (redeclaring with a default is an
     * incompatible property composition and fatals on PHP 8.5).
     */
    public function __construct()
    {
        $this->onQueue('v5-reconcile');
    }

    public function handle(): void
    {
        $this->dispatchReconcileJobs();
        $this->pruneContainerStatuses();
    }

    private function dispatchReconcileJobs(): void
    {
        V5Server::query()
            // Unreachable servers stay in the loop so a recovered node is
            // restored to installed by its next successful reconcile.
            ->whereIn('status', [ServerStatus::Installed->value, ServerStatus::Unreachable->value])
            ->where('has_coold', true)
            ->get()
            ->each(function (V5Server $server): void {
                try {
                    V5ReconcileServerStateJob::dispatch($server->id);
                } catch (\Throwable $exception) {
                    Log::warning('V5 reconcile dispatch failed for a server.', [
                        'server_id' => $server->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });
    }

    private function pruneContainerStatuses(): void
    {
        $cutoff = now()->subHours(self::CONTAINER_STATUS_TTL_HOURS);
        $liveContainerIds = V5Application::query()
            ->whereNotNull('runtime_container_id')
            ->pluck('runtime_container_id')
            ->all();

        ContainerStatus::query()
            ->where(function ($query) use ($cutoff): void {
                $query
                    ->where('last_seen_at', '<', $cutoff)
                    ->orWhere(function ($query) use ($cutoff): void {
                        $query->whereNull('last_seen_at')->where('created_at', '<', $cutoff);
                    })
                    ->orWhereNotIn('server_id', V5Server::query()->select('id'));
            })
            ->when($liveContainerIds !== [], fn ($query) => $query->whereNotIn('container_id', $liveContainerIds))
            ->delete();
    }
}
