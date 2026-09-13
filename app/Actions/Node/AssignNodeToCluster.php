<?php

namespace App\Actions\Node;

use App\Models\Node;
use App\Models\NodeCluster;
use DomainException;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class AssignNodeToCluster
{
    use AsAction;

    public function handle(NodeCluster $cluster, Node $node): Node
    {
        if ($cluster->team_id !== $node->team_id) {
            throw new DomainException('The Node and cluster must belong to the same team.');
        }

        return DB::transaction(function () use ($cluster, $node): Node {
            $cluster = NodeCluster::query()->lockForUpdate()->findOrFail($cluster->id);
            $node = Node::query()->lockForUpdate()->findOrFail($node->id);
            if ($node->node_cluster_id === $cluster->id && $node->wireguard_ip && $node->workload_cidr) {
                return $node;
            }
            if ($node->node_cluster_id !== null && $node->node_cluster_id !== $cluster->id) {
                throw new DomainException('The Node already belongs to another cluster. Remove it before assigning it again.');
            }
            $ip = $node->wireguard_ip;
            if ($node->node_cluster_id === null) {
                if ($cluster->nodes()->count() >= 100) {
                    throw new DomainException('A full-mesh cluster supports at most 100 Nodes.');
                }
                [$network, $prefix] = explode('/', $cluster->cidr);
                $base = (int) sprintf('%u', ip2long($network));
                $lastHostOffset = (2 ** (32 - (int) $prefix)) - 2;
                $used = $cluster->nodes()->pluck('wireguard_ip')->all();
                $ip = null;
                for ($i = 2; $i <= $lastHostOffset; $i++) {
                    $candidate = long2ip($base + $i);
                    if (! in_array($candidate, $used, true)) {
                        $ip = $candidate;
                        break;
                    }
                }
                if (! $ip) {
                    throw new DomainException('No Node address is available in this cluster.');
                }
            }
            $workloadCidr = $node->workload_cidr ?? $this->nextWorkloadCidr();
            $node->update(['node_cluster_id' => $cluster->id, 'wireguard_ip' => $ip, 'workload_cidr' => $workloadCidr, 'network_applied_revision' => null]);
            $cluster->increment('desired_revision');

            return $node;
        });
    }

    private function nextWorkloadCidr(): string
    {
        $used = Node::query()->whereNotNull('workload_cidr')->pluck('workload_cidr')->all();
        for ($index = 0; $index < 16384; $index++) {
            $secondOctet = 64 + intdiv($index, 256);
            $thirdOctet = $index % 256;
            $candidate = "100.{$secondOctet}.{$thirdOctet}.0/24";
            if (! in_array($candidate, $used, true)) {
                return $candidate;
            }
        }

        throw new DomainException('No workload subnet is available.');
    }
}
