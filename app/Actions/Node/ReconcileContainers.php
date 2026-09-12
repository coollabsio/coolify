<?php

namespace App\Actions\Node;

use App\Enums\NodeContainerManagementState;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeContainer;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Lorisleiva\Actions\Concerns\AsAction;

class ReconcileContainers
{
    use AsAction;

    /**
     * @param  list<array<string, mixed>>  $observations
     */
    public function handle(Node $node, array $observations): void
    {
        $containers = Validator::make(['containers' => $observations], [
            'containers' => ['array'],
            'containers.*.runtime_id' => ['required', 'string', 'max:128', 'distinct'],
            'containers.*.name' => ['required', 'string', 'max:255'],
            'containers.*.image' => ['required', 'string', 'max:2048'],
            'containers.*.state' => ['required', 'string', 'max:100'],
            'containers.*.health_status' => ['nullable', 'string', 'max:100'],
            'containers.*.restart_count' => ['nullable', 'integer', 'min:0'],
            'containers.*.ports' => ['nullable', 'array'],
            'containers.*.labels' => ['present', 'array'],
            'containers.*.labels.*' => ['nullable', 'string', 'max:4096'],
            'containers.*.created_at' => ['nullable', 'date'],
            'containers.*.started_at' => ['nullable', 'date'],
            'containers.*.observed_at' => ['required', 'date'],
        ])->validate()['containers'];

        DB::transaction(function () use ($node, $containers): void {
            $runtimeIds = [];

            foreach ($containers as $container) {
                $runtimeIds[] = $container['runtime_id'];
                $ownership = $this->resolveOwnership($node, $container['labels']);

                NodeContainer::query()->updateOrCreate(
                    ['node_id' => $node->id, 'runtime_id' => $container['runtime_id']],
                    [
                        'node_workload_id' => $ownership['workload']?->id,
                        'node_workload_revision_id' => $ownership['revision']?->id,
                        'name' => $container['name'],
                        'image' => $container['image'],
                        'state' => $container['state'],
                        'health_status' => $container['health_status'] ?? null,
                        'restart_count' => $container['restart_count'] ?? null,
                        'ports' => $container['ports'] ?? null,
                        'labels' => $container['labels'],
                        'management_state' => $ownership['state'],
                        'is_managed' => $ownership['state'] === NodeContainerManagementState::MANAGED,
                        'runtime_created_at' => isset($container['created_at']) ? Carbon::parse($container['created_at']) : null,
                        'runtime_started_at' => isset($container['started_at']) ? Carbon::parse($container['started_at']) : null,
                        'observed_at' => Carbon::parse($container['observed_at']),
                    ],
                );
            }

            $missing = NodeContainer::query()->where('node_id', $node->id);
            if ($runtimeIds !== []) {
                $missing->whereNotIn('runtime_id', $runtimeIds);
            }
            $missing->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $labels
     * @return array{state: NodeContainerManagementState, workload: ?NodeWorkload, revision: ?NodeWorkloadRevision}
     */
    private function resolveOwnership(Node $node, array $labels): array
    {
        $hasCoolifyLabels = collect(array_keys($labels))->contains(
            fn (string $key): bool => str_starts_with($key, 'coolify.'),
        );
        if (! $hasCoolifyLabels) {
            return $this->ownership(NodeContainerManagementState::EXTERNAL);
        }

        if (($labels['coolify.managed'] ?? null) !== 'true'
            || ($labels['coolify.instance'] ?? null) !== InstanceSettings::get()->ensureInstanceUuid()) {
            return $this->ownership(NodeContainerManagementState::UNRECOGNIZED);
        }

        $workload = NodeWorkload::query()
            ->where('uuid', $labels['coolify.workload'] ?? null)
            ->where('team_id', $node->team_id)
            ->whereHas('nodes', fn ($query) => $query->whereKey($node->id))
            ->first();
        if ($workload === null) {
            return $this->ownership(NodeContainerManagementState::UNRECOGNIZED);
        }

        $revision = NodeWorkloadRevision::query()
            ->where('uuid', $labels['coolify.revision'] ?? null)
            ->where('node_workload_id', $workload->id)
            ->first();
        if ($revision === null) {
            return $this->ownership(NodeContainerManagementState::UNRECOGNIZED);
        }

        return $this->ownership(NodeContainerManagementState::MANAGED, $workload, $revision);
    }

    /** @return array{state: NodeContainerManagementState, workload: ?NodeWorkload, revision: ?NodeWorkloadRevision} */
    private function ownership(
        NodeContainerManagementState $state,
        ?NodeWorkload $workload = null,
        ?NodeWorkloadRevision $revision = null,
    ): array {
        return compact('state', 'workload', 'revision');
    }
}
