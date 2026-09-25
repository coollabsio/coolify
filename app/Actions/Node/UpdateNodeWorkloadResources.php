<?php

namespace App\Actions\Node;

use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class UpdateNodeWorkloadResources
{
    use AsAction;

    /**
     * @param  array{cpu_limit: ?float, cpu_reservation: ?float, memory_limit_bytes: ?int, memory_reservation_bytes: ?int}  $resources
     */
    public function handle(NodeWorkload $workload, array $resources): NodeWorkloadRevision
    {
        return DB::transaction(function () use ($workload, $resources): NodeWorkloadRevision {
            $workload = NodeWorkload::query()->lockForUpdate()->findOrFail($workload->id);
            $current = $workload->revisions()->latest('id')->lockForUpdate()->firstOrFail();
            $resources = array_filter($resources, fn ($value): bool => $value !== null);
            $configuration = $current->configuration ?? [];
            $currentResources = $configuration['resources'] ?? [];
            if ($currentResources === $resources) {
                return $current;
            }
            if ($resources === []) {
                unset($configuration['resources']);
            } else {
                $configuration['resources'] = $resources;
            }

            return $workload->createRevision($current->image, $configuration, $current->environment ?? []);
        });
    }
}
