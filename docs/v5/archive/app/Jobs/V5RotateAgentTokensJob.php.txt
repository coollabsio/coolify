<?php

namespace App\Jobs;

use App\Enums\V5\ServerStatus;
use App\Models\V5\Server as V5Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled fan-out for host JWT rotation: dispatches one V5RotateAgentTokenJob
 * per managed server whose on-disk token is missing or within the configured
 * refresh threshold of expiry, so a fresh token is always on disk before the
 * current one lapses.
 */
class V5RotateAgentTokensJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    /**
     * Rotation shares the reconcile queue so the hourly fleet fan-out can never
     * starve user-triggered deploys and bootstraps on the default queue. Set via
     * onQueue() rather than a `$queue` property redeclaration, which the
     * Queueable trait already defines (redeclaring with a default is an
     * incompatible property composition and fatals on PHP 8.5).
     */
    public function __construct()
    {
        $this->onQueue('v5-reconcile');
    }

    public function handle(): void
    {
        $threshold = now()->addSeconds((int) config('flux.host_token_refresh_threshold'));

        V5Server::query()
            ->where('status', ServerStatus::Installed->value)
            ->where('has_coold', true)
            ->whereNotNull('last_bootstrapped_at')
            ->where(function ($query) use ($threshold): void {
                $query
                    ->whereNull('agent_token_expires_at')
                    ->orWhere('agent_token_expires_at', '<', $threshold);
            })
            ->get()
            ->each(function (V5Server $server): void {
                try {
                    V5RotateAgentTokenJob::dispatch($server->id);
                } catch (\Throwable $exception) {
                    Log::warning('V5 token rotation dispatch failed for a server.', [
                        'server_id' => $server->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });
    }
}
