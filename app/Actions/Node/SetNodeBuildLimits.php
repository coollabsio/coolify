<?php

namespace App\Actions\Node;

use App\Actions\Node\Build\StartQueuedNodeBuilds;
use App\Models\Node;
use App\Models\NodeBuild;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Sets the build slots and the BuildKit limits of a Node (decision 0035). Only a team admin may
 * change them (NodePolicy::update). A changed BuildKit limit goes to the Node with the next network
 * revision; Sentinel replaces BuildKit when no build runs there. More slots start waiting builds.
 */
class SetNodeBuildLimits
{
    use AsAction;

    private const GIB = 1024 * 1024 * 1024;

    /**
     * @param  array{concurrent_builds?: mixed, memory_limit_gb?: mixed, cpu_limit?: mixed, cache_gb?: mixed}  $input  Empty limits use the default of Sentinel.
     */
    public function handle(Node $node, array $input, User $user): Node
    {
        Gate::forUser($user)->authorize('update', $node);
        $validated = Validator::make($input, [
            'concurrent_builds' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.NodeBuild::MAX_CONCURRENT_BUILDS],
            'memory_limit_gb' => ['sometimes', 'nullable', 'numeric', 'min:0.25', 'max:4096'],
            'cpu_limit' => ['sometimes', 'nullable', 'numeric', 'min:0.1', 'max:1024'],
            'cache_gb' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65536'],
        ])->validate();

        $changes = [];
        if (array_key_exists('concurrent_builds', $validated)) {
            $changes['concurrent_builds'] = (int) $validated['concurrent_builds'];
        }
        if (array_key_exists('memory_limit_gb', $validated)) {
            $changes['build_memory_limit_bytes'] = filled($validated['memory_limit_gb']) ? (int) round((float) $validated['memory_limit_gb'] * self::GIB) : null;
        }
        if (array_key_exists('cpu_limit', $validated)) {
            $changes['build_cpu_limit_millis'] = filled($validated['cpu_limit']) ? (int) round((float) $validated['cpu_limit'] * 1000) : null;
        }
        if (array_key_exists('cache_gb', $validated)) {
            $changes['build_cache_bytes'] = filled($validated['cache_gb']) ? (int) $validated['cache_gb'] * self::GIB : null;
        }
        $node->fill($changes);
        if (! $node->isDirty()) {
            return $node;
        }
        $limitsChanged = $node->isDirty(['build_memory_limit_bytes', 'build_cpu_limit_millis', 'build_cache_bytes']);
        $node->save();
        if ($limitsChanged && $node->cluster !== null) {
            QueueNodeClusterNetworkRevision::run($node->cluster, $user);
        }
        if ($node->node_cluster_id !== null) {
            StartQueuedNodeBuilds::run((int) $node->node_cluster_id);
        }

        return $node->refresh();
    }

    /**
     * The settings of the Node in the units of the form and the API.
     *
     * @return array{concurrent_builds: int, memory_limit_gb: ?float, cpu_limit: ?float, cache_gb: ?int}
     */
    public static function values(Node $node): array
    {
        return [
            'concurrent_builds' => (int) $node->concurrent_builds,
            'memory_limit_gb' => $node->build_memory_limit_bytes === null ? null : round($node->build_memory_limit_bytes / self::GIB, 2),
            'cpu_limit' => $node->build_cpu_limit_millis === null ? null : $node->build_cpu_limit_millis / 1000,
            'cache_gb' => $node->build_cache_bytes === null ? null : intdiv($node->build_cache_bytes, self::GIB),
        ];
    }
}
