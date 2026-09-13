<?php

namespace App\Actions\Node;

use App\Models\NodeCluster;
use App\Rules\PrivateIpv4Cidr;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

class UpdateNodeCluster
{
    use AsAction;

    public function handle(NodeCluster $cluster, string $name, ?string $description, string $cidr, string $interface, int $port): NodeCluster
    {
        try {
            $cidr = PrivateIpv4Cidr::range($cidr)['canonical'];
        } catch (InvalidArgumentException $exception) {
            throw new DomainException($exception->getMessage(), previous: $exception);
        }

        return DB::transaction(function () use ($cluster, $name, $description, $cidr, $interface, $port): NodeCluster {
            $cluster = NodeCluster::query()->lockForUpdate()->findOrFail($cluster->id);
            if ($cluster->hasActivatedNetwork() && $cidr !== $cluster->cidr) {
                throw new DomainException('An active cluster CIDR cannot be changed.');
            }
            if (NodeCluster::query()->whereKeyNot($cluster->id)->get(['cidr'])->contains(fn (NodeCluster $other): bool => PrivateIpv4Cidr::overlaps($other->cidr, $cidr))) {
                throw new DomainException('The cluster CIDR overlaps an existing cluster network.');
            }

            $networkChanged = $cluster->cidr !== $cidr || $cluster->wireguard_interface !== $interface || $cluster->wireguard_port !== $port;
            $cluster->fill(['name' => $name, 'description' => $description, 'cidr' => $cidr, 'wireguard_interface' => $interface, 'wireguard_port' => $port]);
            if ($networkChanged) {
                $cluster->desired_revision++;
            }
            $cluster->save();

            return $cluster;
        });
    }
}
