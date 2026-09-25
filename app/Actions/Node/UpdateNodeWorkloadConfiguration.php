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
     * @param  list<array{host_port: int, container_port: int, protocol: string}>  $ports
     * @param  array<string, string>  $environment
     */
    public function handle(NodeWorkload $workload, array $command, array $ports, array $environment): NodeWorkloadRevision
    {
        return DB::transaction(function () use ($workload, $command, $ports, $environment): NodeWorkloadRevision {
            $workload = NodeWorkload::query()->lockForUpdate()->findOrFail($workload->id);
            $current = $workload->revisions()->latest('id')->lockForUpdate()->firstOrFail();
            $configuration = $current->configuration ?? [];
            unset($configuration['command'], $configuration['ports']);
            if ($command !== []) {
                $configuration['command'] = $command;
            }
            if ($ports !== []) {
                $configuration['ports'] = $ports;
            }
            if ($configuration === ($current->configuration ?? []) && $environment === ($current->environment ?? [])) {
                return $current;
            }

            return $workload->createRevision($current->image, $configuration, $environment);
        });
    }
}
