<?php

namespace App\Actions\Node;

use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class UpdateNodeWorkloadConfiguration
{
    use AsAction;

    /**
     * @param  list<string>  $command
     * @param  array<string, string>|null  $environment  Null keeps the current revision environment.
     */
    public function handle(NodeWorkload $workload, array $command, ?array $environment = null): NodeWorkloadRevision
    {
        return DB::transaction(function () use ($workload, $command, $environment): NodeWorkloadRevision {
            $workload = NodeWorkload::query()->lockForUpdate()->findOrFail($workload->id);
            $current = $workload->revisions()->latest('id')->lockForUpdate()->firstOrFail();
            $configuration = $current->configuration ?? [];
            $environment ??= $current->environment ?? [];
            unset($configuration['command'], $configuration['ports']);
            if ($command !== []) {
                $configuration['command'] = $command;
            }
            if ($configuration === ($current->configuration ?? []) && $environment === ($current->environment ?? [])) {
                return $current;
            }

            return $workload->createRevision($current->image, $configuration, $environment);
        });
    }
}
