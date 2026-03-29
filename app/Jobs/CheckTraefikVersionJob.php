<?php

namespace App\Jobs;

use App\Enums\ProxyTypes;
use App\Models\Server;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

class CheckTraefikVersionJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public function handle(): void
    {
        // Load versions from cached data
        $traefikVersions = get_traefik_versions();

        if (empty($traefikVersions)) {
            return;
        }

        // Query all servers with Traefik proxy that are reachable
        $servers = Server::whereNotNull('proxy')
            ->whereProxyType(ProxyTypes::TRAEFIK->value)
            ->whereRelation('settings', 'is_reachable', true)
            ->whereRelation('settings', 'is_usable', true)
            ->get();

        if ($servers->isEmpty()) {
            return;
        }

        $checkedAt = now()->toIso8601String();
        $jobs = $servers
            ->map(fn (Server $server) => new CheckTraefikVersionForServerJob($server, $traefikVersions, false, $checkedAt))
            ->all();

        Bus::batch($jobs)
            ->finally(function (Batch $batch) use ($checkedAt): void {
                if ($batch->cancelled()) {
                    return;
                }

                NotifyOutdatedTraefikServersJob::dispatch($checkedAt);
            })
            ->dispatch();
    }
}
