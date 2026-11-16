<?php

namespace App\Actions\Node;

use App\Enums\NodeWorkloadDesiredState;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadSetEntry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Sends the desired set of the workloads of a Node to Sentinel (decision 0026). Coolify keeps one
 * entry for each workload that Sentinel converges, and on the Node the generation and the hash of
 * the desired set. A change sends the entries of some workloads with the new generation and the
 * hash of the full set; Sentinel answers `accepted: false` when its hash then differs, and Coolify
 * sends the full set. An entry of a workload that is no longer on the Node is removed: Sentinel
 * removes its containers. This is also the fence after a recovery move (decision 0014): the first
 * set after a reconnect does not have the moved workload.
 */
class SyncNodeWorkloadSet
{
    use AsAction;

    public const CAPABILITY = 'workload.reconcile.v1';

    /**
     * Whether Sentinel converges this workload on this Node. The rest keeps the command path. A
     * published port forwards to one container (decision 0016): the Nodes must forward it to the new
     * container before the old one goes, so a workload with published ports keeps the command path.
     * Volumes, databases, and stack services converge; DeployNodeStackJob keeps the `depends_on` order.
     */
    public static function converges(Node $node, NodeWorkload $workload): bool
    {
        return $node->supportsCapability(self::CAPABILITY) === true
            && ! $workload->publishedPorts()->exists();
    }

    /**
     * @param  list<int>|null  $workloadIds  The workloads that changed. Null sends the full set.
     * @param  array<int, array{payload: array<string, mixed>, network_revision: int}>  $prepared  Deploys that the caller built already, by workload ID.
     * @param  bool  $confirm  Only confirm the stored generation when the hashes are equal (after a reconnect).
     * @return array{sent: bool, accepted: bool, generation: int, set_hash: string}
     */
    public function handle(Node $node, ?array $workloadIds = null, array $prepared = [], bool $confirm = false): array
    {
        return Cache::lock("node-workload-set:{$node->id}", 120)->block(60, function () use ($node, $workloadIds, $prepared, $confirm): array {
            $node->refresh();
            $entries = $this->currentEntries($node);
            $hash = self::setHash($entries);
            $node->forceFill(['workload_set_hash' => $hash])->saveQuietly();
            if ($node->supportsCapability(self::CAPABILITY) !== true) {
                return ['sent' => false, 'accepted' => false, 'generation' => (int) $node->workload_set_generation, 'set_hash' => $hash];
            }
            $removed = $entries['removed'];
            /** @var Collection<int, NodeWorkloadSetEntry> $kept */
            $kept = $entries['kept'];
            if ($confirm) {
                if ((int) $node->workload_set_generation === 0 && $kept->isEmpty()) {
                    return ['sent' => false, 'accepted' => true, 'generation' => 0, 'set_hash' => $hash];
                }

                return $this->send($node, (int) $node->workload_set_generation, $hash, false, [], [], []);
            }
            $full = $workloadIds === null;
            $changed = $full ? $kept : $kept->whereIn('node_workload_id', $workloadIds);
            $removes = $full ? [] : $removed->pluck('uuid')->all();
            $result = $this->send($node, (int) $node->workload_set_generation + 1, $hash, $full, $this->puts($changed, $prepared), $removes, []);
            if (! $result['accepted'] && ! $full) {
                // A lost change, or Sentinel lost its set: send all of it.
                $result = $this->send($node, max((int) $node->refresh()->workload_set_generation, $result['generation']) + 1, $hash, true, $this->puts($kept, $prepared), [], []);
            } elseif (! $result['accepted'] && $result['generation'] >= (int) $node->refresh()->workload_set_generation) {
                // Sentinel has a newer generation than Postgres, for example after a database restore.
                $result = $this->send($node, $result['generation'] + 1, $hash, true, $this->puts($kept, $prepared), [], []);
            }

            return $result;
        });
    }

    /** Stores the hash of the desired set from Postgres, without a message to Sentinel. */
    public function refreshHash(Node $node): string
    {
        return Cache::lock("node-workload-set:{$node->id}", 120)->block(60, function () use ($node): string {
            $hash = self::setHash($this->currentEntries($node));
            $node->forceFill(['workload_set_hash' => $hash])->saveQuietly();

            return $hash;
        });
    }

    /**
     * Lets Sentinel forget a workload without a change of its containers, because Coolify manages it
     * with commands again.
     */
    public static function release(Node $node, NodeWorkload $workload): void
    {
        $entry = NodeWorkloadSetEntry::query()->where('node_id', $node->id)->where('node_workload_id', $workload->id)->first();
        if ($entry === null) {
            return;
        }
        Cache::lock("node-workload-set:{$node->id}", 120)->block(60, function () use ($node, $workload, $entry): void {
            $entry->delete();
            $node->refresh();
            $entries = (new self)->currentEntries($node);
            $hash = self::setHash($entries);
            $node->forceFill(['workload_set_hash' => $hash])->saveQuietly();
            if ($node->supportsCapability(self::CAPABILITY) === true) {
                rescue(fn () => (new self)->send($node, (int) $node->workload_set_generation + 1, $hash, false, [], [], [$workload->uuid]));
            }
        });
    }

    /**
     * The entries of the Node. An entry of a workload that is no longer on the Node, or that is
     * removed, is deleted.
     *
     * @return array{kept: Collection<int, NodeWorkloadSetEntry>, removed: Collection<int, NodeWorkload>}
     */
    private function currentEntries(Node $node): array
    {
        $entries = NodeWorkloadSetEntry::query()
            ->with(['workload' => fn ($query) => $query->withoutGlobalScopes(), 'revision', 'operation'])
            ->where('node_id', $node->id)
            ->get()
            ->each(fn (NodeWorkloadSetEntry $entry) => $entry->setRelation('node', $node));
        $attached = $node->workloads()->withoutGlobalScopes()->pluck('node_workloads.id')->all();
        [$kept, $gone] = $entries->partition(fn (NodeWorkloadSetEntry $entry): bool => $entry->workload !== null
            && $entry->revision !== null
            && in_array($entry->node_workload_id, $attached, true)
            && $entry->workload->desired_state !== NodeWorkloadDesiredState::REMOVED);
        foreach ($gone as $entry) {
            $entry->delete();
        }
        // Only workloads that were in the set: a workload of the command path keeps its containers.
        $removed = $gone->map(fn (NodeWorkloadSetEntry $entry): ?NodeWorkload => $entry->workload)->filter()->values();
        foreach ($kept as $entry) {
            $entryHash = self::entryHash($entry);
            if ($entry->entry_hash !== $entryHash) {
                $entry->forceFill(['entry_hash' => $entryHash])->saveQuietly();
            }
        }

        return ['kept' => $kept->values(), 'removed' => $removed->unique('id')->values()];
    }

    public static function entryHash(NodeWorkloadSetEntry $entry): string
    {
        return hash('sha256', json_encode([
            $entry->workload->uuid,
            $entry->revision->uuid,
            $entry->revision->configuration_hash,
            $entry->desired_state->value,
            (int) $entry->network_revision,
            $entry->container_slot->containerName($entry->workload),
            $entry->replaces_slot?->containerName($entry->workload),
            $entry->operation?->uuid,
            (bool) $entry->redeploy,
            $entry->pull_policy,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * The hash of the full set, as Sentinel computes it: SHA-256 of the lines `<workload_id>
     * <entry_hash>\n`, sorted by workload ID.
     *
     * @param  array{kept: Collection<int, NodeWorkloadSetEntry>}  $entries
     */
    public static function setHash(array $entries): string
    {
        $lines = $entries['kept']
            ->map(fn (NodeWorkloadSetEntry $entry): array => [$entry->workload->uuid, $entry->entry_hash])
            ->sortBy(fn (array $line): string => $line[0], SORT_STRING)
            ->map(fn (array $line): string => "{$line[0]} {$line[1]}\n")
            ->implode('');

        return hash('sha256', $lines);
    }

    /**
     * @param  Collection<int, NodeWorkloadSetEntry>  $entries
     * @param  array<int, array{payload: array<string, mixed>, network_revision: int}>  $prepared
     * @return list<array<string, mixed>>
     */
    private function puts(Collection $entries, array $prepared): array
    {
        return $entries->map(function (NodeWorkloadSetEntry $entry) use ($prepared): array {
            $deploy = $prepared[$entry->node_workload_id]['payload'] ?? $this->deployPayload($entry);

            return [
                'workload_id' => $entry->workload->uuid,
                'revision_id' => $entry->revision->uuid,
                'configuration_hash' => (string) $entry->revision->configuration_hash,
                'desired_state' => $entry->desired_state->value,
                'network_revision' => (int) $entry->network_revision,
                'deploy' => $deploy,
                'replaces' => $entry->replaces_slot?->containerName($entry->workload) ?? '',
                'operation_id' => $entry->operation?->uuid ?? '',
                'entry_hash' => $entry->entry_hash,
                'redeploy' => (bool) $entry->redeploy,
                // A stop of a move or a restore gives a database time to flush its data.
                'stop_timeout_seconds' => data_get($entry->operation?->request, 'stop_timeout_seconds'),
            ];
        })->values()->all();
    }

    /**
     * Builds the container of an entry again, for the full set. The stored plan of the entry keeps
     * the container name, so this has no side effect on the slots.
     *
     * @return array<string, mixed>
     */
    private function deployPayload(NodeWorkloadSetEntry $entry): array
    {
        $operation = new NodeOperation;
        $operation->forceFill([
            'uuid' => $entry->operation?->uuid ?? (string) Str::uuid(),
            'command_type' => 'workload.deploy.v1',
            'node_id' => $entry->node_id,
            'node_workload_id' => $entry->node_workload_id,
            'node_workload_revision_id' => $entry->node_workload_revision_id,
            'request' => [
                'pull_policy' => $entry->pull_policy,
                'container' => ['slot' => $entry->container_slot->value, 'replaces' => $entry->replaces_slot?->value],
            ],
        ]);
        $operation->setRelation('node', $entry->node);
        $operation->setRelation('workload', $entry->workload);
        $operation->setRelation('revision', $entry->revision);
        $operation->setRelation('requestedBy', null);

        return DispatchWorkloadDeployment::make()->prepare($operation)['payload'];
    }

    /**
     * @param  list<array<string, mixed>>  $puts
     * @param  list<string>  $removes
     * @param  list<string>  $releases
     * @return array{sent: bool, accepted: bool, generation: int, set_hash: string}
     */
    private function send(Node $node, int $generation, string $hash, bool $full, array $puts, array $removes, array $releases): array
    {
        $url = config('constants.flux.internal_url');
        $token = config('constants.flux.internal_token');
        if (! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
            throw new RuntimeException('Flux internal API configuration is incomplete.');
        }
        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(130)
                ->post(rtrim($url, '/').'/v1/commands/workload.reconcile', [
                    'server_id' => $node->uuid,
                    'command_id' => (string) Str::uuid(),
                    'generation' => $generation,
                    'set_hash' => $hash,
                    'full' => $full,
                    'puts' => $puts,
                    'removes' => $removes,
                    'releases' => $releases,
                ]);
        } catch (ConnectionException) {
            return ['sent' => false, 'accepted' => false, 'generation' => (int) $node->workload_set_generation, 'set_hash' => $hash];
        }
        if (in_array($response->status(), [404, 503, 504], true)) {
            // The Node is offline. Its Hello carries its generation and hash; a difference sends the full set.
            return ['sent' => false, 'accepted' => false, 'generation' => (int) $node->workload_set_generation, 'set_hash' => $hash];
        }
        $response->throw();
        $accepted = (bool) $response->json('accepted');
        $reported = (int) $response->json('generation');
        if ($accepted || $reported > (int) $node->workload_set_generation) {
            $node->forceFill(['workload_set_generation' => max($reported, $accepted ? $generation : 0)])->saveQuietly();
        }
        foreach ((array) $response->json('statuses', []) as $status) {
            if (is_array($status)) {
                rescue(fn () => RecordNodeWorkloadStatus::run($node, $status, fromEvent: false));
            }
        }

        return ['sent' => true, 'accepted' => $accepted, 'generation' => $reported, 'set_hash' => (string) $response->json('set_hash')];
    }
}
