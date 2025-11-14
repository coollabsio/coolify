<?php

namespace App\Jobs;

use App\Enums\ProxyTypes;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class CheckTraefikVersionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public function handle(): void
    {
        try {
            Log::info('CheckTraefikVersionJob: Starting Traefik version check with parallel processing');

            // Load versions from versions.json
            $versionsPath = base_path('versions.json');
            if (! File::exists($versionsPath)) {
                Log::warning('CheckTraefikVersionJob: versions.json not found, skipping check');

                return;
            }

            $allVersions = json_decode(File::get($versionsPath), true);
            $traefikVersions = data_get($allVersions, 'traefik');

            if (empty($traefikVersions) || ! is_array($traefikVersions)) {
                Log::warning('CheckTraefikVersionJob: Traefik versions not found or invalid in versions.json');

                return;
            }

            $branches = array_keys($traefikVersions);
            Log::info('CheckTraefikVersionJob: Loaded Traefik version branches', ['branches' => $branches]);

            // Query all servers with Traefik proxy that are reachable
            $servers = Server::whereNotNull('proxy')
                ->whereProxyType(ProxyTypes::TRAEFIK->value)
                ->whereRelation('settings', 'is_reachable', true)
                ->whereRelation('settings', 'is_usable', true)
                ->get();

            $serverCount = $servers->count();
            Log::info("CheckTraefikVersionJob: Found {$serverCount} server(s) with Traefik proxy");

            if ($serverCount === 0) {
                Log::info('CheckTraefikVersionJob: No Traefik servers found, job completed');

                return;
            }

            // Dispatch individual server check jobs in parallel
            Log::info('CheckTraefikVersionJob: Dispatching parallel server check jobs');

            foreach ($servers as $server) {
                CheckTraefikVersionForServerJob::dispatch($server, $traefikVersions);
            }

            Log::info("CheckTraefikVersionJob: Dispatched {$serverCount} parallel server check jobs");

            // Dispatch notification job with delay to allow server checks to complete
            // For 1000 servers with 60s timeout each, we need at least 60s delay
            // But jobs run in parallel via queue workers, so we only need enough time
            // for the slowest server to complete
            $delaySeconds = min(300, max(60, (int) ($serverCount / 10))); // 60s minimum, 300s maximum, 0.1s per server
            NotifyOutdatedTraefikServersJob::dispatch()->delay(now()->addSeconds($delaySeconds));

            Log::info("CheckTraefikVersionJob: Scheduled notification job with {$delaySeconds}s delay");
            Log::info('CheckTraefikVersionJob: Job completed successfully - parallel processing initiated');
        } catch (\Throwable $e) {
            Log::error('CheckTraefikVersionJob: Error checking Traefik versions: '.$e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
