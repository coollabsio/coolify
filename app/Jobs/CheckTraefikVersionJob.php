<?php

namespace App\Jobs;

use App\Enums\ProxyTypes;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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

        // Dispatch individual server check jobs in parallel
        foreach ($servers as $server) {
            CheckTraefikVersionForServerJob::dispatch($server, $traefikVersions, false, $checkedAt);
        }

        $delaySeconds = $this->calculateNotificationDelay($servers->count());
        if (isDev()) {
            $delaySeconds = 1;
        }

        NotifyOutdatedTraefikServersJob::dispatch($checkedAt)->delay(now()->addSeconds($delaySeconds));
    }

    protected function calculateNotificationDelay(int $serverCount): int
    {
        $minDelay = config('constants.server_checks.notification_delay_min');
        $maxDelay = config('constants.server_checks.notification_delay_max');
        $scalingFactor = config('constants.server_checks.notification_delay_scaling');

        $calculatedDelay = (int) ($serverCount * $scalingFactor);

        return min($maxDelay, max($minDelay, $calculatedDelay));
    }
}
