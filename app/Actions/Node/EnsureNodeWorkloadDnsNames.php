<?php

namespace App\Actions\Node;

use App\Models\Node;
use App\Models\NodeWorkload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

class EnsureNodeWorkloadDnsNames
{
    use AsAction;

    /** @return array<int, string> */
    public function handle(Node $node): array
    {
        if ($node->node_cluster_id === null) {
            return [];
        }

        return DB::transaction(function () use ($node): array {
            $workloads = NodeWorkload::query()
                ->whereHas('nodes', fn ($query) => $query->where('node_cluster_id', $node->node_cluster_id))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $usedNames = $workloads
                ->filter(fn (NodeWorkload $workload): bool => filled($workload->internal_dns_name))
                ->pluck('uuid', 'internal_dns_name')
                ->all();

            foreach ($workloads->whereNull('internal_dns_name') as $workload) {
                $baseName = $this->baseName($workload);
                $dnsName = array_key_exists($baseName, $usedNames)
                    ? $this->suffixedName($baseName, $workload->uuid, $usedNames)
                    : $baseName;
                $workload->update(['internal_dns_name' => $dnsName]);
                $usedNames[$dnsName] = $workload->uuid;
            }

            return $workloads->pluck('internal_dns_name', 'id')->all();
        });
    }

    private function baseName(NodeWorkload $workload): string
    {
        $name = Str::slug($workload->name);

        return Str::limit($name !== '' ? $name : strtolower($workload->uuid), 63, '');
    }

    /** @param array<string, string> $usedNames */
    private function suffixedName(string $baseName, string $uuid, array $usedNames): string
    {
        for ($length = 8; $length <= strlen($uuid); $length++) {
            $suffix = strtolower(substr($uuid, 0, $length));
            $candidate = Str::limit($baseName, 63 - strlen($suffix) - 1, '').'-'.$suffix;
            if (! array_key_exists($candidate, $usedNames)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('A unique internal DNS name could not be allocated.');
    }
}
