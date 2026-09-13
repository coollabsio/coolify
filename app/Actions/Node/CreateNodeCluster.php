<?php

namespace App\Actions\Node;

use App\Models\NodeCluster;
use App\Models\Team;
use App\Models\User;
use App\Rules\PrivateIpv4Cidr;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

class CreateNodeCluster
{
    use AsAction;

    public function handle(Team $team, User $creator, string $name, ?string $description = null, ?string $cidr = null): NodeCluster
    {
        return Cache::lock('node-cluster:cidr-allocation', 10)->block(5, function () use ($team, $creator, $name, $description, $cidr): NodeCluster {
            return DB::transaction(function () use ($team, $creator, $name, $description, $cidr): NodeCluster {
                $cidr ??= $this->nextCidr();
                try {
                    $cidr = PrivateIpv4Cidr::range($cidr)['canonical'];
                } catch (InvalidArgumentException $exception) {
                    throw new DomainException($exception->getMessage(), previous: $exception);
                }
                if (NodeCluster::query()->lockForUpdate()->get(['cidr'])->contains(fn (NodeCluster $cluster): bool => PrivateIpv4Cidr::overlaps($cluster->cidr, $cidr))) {
                    throw new DomainException('The cluster CIDR overlaps an existing cluster network.');
                }

                return NodeCluster::query()->create(['team_id' => $team->id, 'created_by_user_id' => $creator->id, 'name' => $name, 'description' => $description, 'cidr' => $cidr]);
            });
        });
    }

    private function nextCidr(): string
    {
        $used = NodeCluster::query()->pluck('cidr')->all();
        for ($i = 0; $i < 4096; $i++) {
            $cidr = '10.'.(240 + intdiv($i, 256)).'.'.($i % 256).'.0/24';
            if (! collect($used)->contains(fn (string $existing): bool => PrivateIpv4Cidr::overlaps($existing, $cidr))) {
                return $cidr;
            }
        }
        throw new DomainException('No automatic cluster CIDR is available.');
    }
}
