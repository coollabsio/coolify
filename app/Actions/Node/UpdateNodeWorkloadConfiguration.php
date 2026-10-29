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
     * @param  string|null  $image  Null keeps the current revision image.
     */
    public function handle(NodeWorkload $workload, array $command, ?array $environment = null, ?string $image = null): NodeWorkloadRevision
    {
        return DB::transaction(function () use ($workload, $command, $environment, $image): NodeWorkloadRevision {
            $workload = NodeWorkload::query()->lockForUpdate()->findOrFail($workload->id);
            $current = $workload->revisions()->latest('id')->lockForUpdate()->firstOrFail();
            $configuration = $current->configuration ?? [];
            $environment ??= $current->environment ?? [];
            $image ??= $current->image;
            unset($configuration['command'], $configuration['ports']);
            if ($command !== []) {
                $configuration['command'] = $command;
            }
            if ($image === $current->image && $configuration === ($current->configuration ?? []) && $environment === ($current->environment ?? [])) {
                return $current;
            }

            return $workload->createRevision($image, $configuration, $environment);
        });
    }
}
