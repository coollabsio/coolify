<?php

namespace App\Jobs;

use App\Actions\Node\InspectNodeClusterDrift;
use App\Models\NodeCluster;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class InspectNodeClusterNetworksJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(): void
    {
        NodeCluster::query()
            ->where('network_status', 'active')
            ->with('team.members')
            ->chunkById(50, function ($clusters): void {
                foreach ($clusters as $cluster) {
                    $user = $cluster->team->members->first(fn ($member): bool => in_array($member->pivot->role, ['owner', 'admin'], true));
                    if ($user === null) {
                        continue;
                    }

                    try {
                        $drifted = InspectNodeClusterDrift::run($cluster);
                    } catch (Throwable) {
                        $drifted = true;
                    }

                    if ($drifted && $cluster->fresh()->network_status === 'active') {
                        $cluster->increment('desired_revision');
                        $cluster->update(['network_status' => 'reconciling']);
                        ReconcileNodeClusterNetworkJob::dispatch($cluster->id, $user->id);
                    }
                }
            });
    }
}
