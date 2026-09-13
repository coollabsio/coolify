<?php

namespace App\Actions\Node;

use App\Models\Node;
use App\Models\NodeWorkload;
use DomainException;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class EnsureNodeWorkloadAddress
{
    use AsAction;

    public function handle(Node $node, NodeWorkload $workload): string
    {
        $node->refresh();
        if ($node->team_id !== $workload->team_id || $node->node_cluster_id === null || blank($node->workload_cidr)) {
            throw new DomainException('The workload and Node must belong to the same configured mesh.');
        }

        return DB::transaction(function () use ($node, $workload): string {
            $node = Node::query()->lockForUpdate()->findOrFail($node->id);
            $assignment = $node->workloads()->whereKey($workload->id)->first();
            if (filled($assignment?->pivot->container_ip)) {
                return $assignment->pivot->container_ip;
            }

            [$network] = explode('/', $node->workload_cidr);
            $base = (int) sprintf('%u', ip2long($network));
            $used = DB::table('node_workload_nodes')->where('node_id', $node->id)->pluck('container_ip')->filter()->all();
            $address = null;
            for ($offset = 2; $offset <= 254; $offset++) {
                $candidate = long2ip($base + $offset);
                if (! in_array($candidate, $used, true)) {
                    $address = $candidate;
                    break;
                }
            }
            if ($address === null) {
                throw new DomainException('No workload address is available on this Node.');
            }

            $node->workloads()->syncWithoutDetaching([$workload->id => ['container_ip' => $address]]);

            return $address;
        });
    }
}
