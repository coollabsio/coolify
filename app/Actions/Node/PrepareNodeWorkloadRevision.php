<?php

namespace App\Actions\Node;

use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class PrepareNodeWorkloadRevision
{
    use AsAction;

    /**
     * Return the revision to deploy. Environment variables are edited on the
     * workload, so a new revision records them when they changed.
     */
    public function handle(NodeWorkload $workload): NodeWorkloadRevision
    {
        return DB::transaction(function () use ($workload): NodeWorkloadRevision {
            $workload = NodeWorkload::query()->lockForUpdate()->findOrFail($workload->id);
            $current = $workload->revisions()->latest('id')->lockForUpdate()->firstOrFail();
            $environment = $workload->runtimeEnvironment();

            // Podman accepts any name and value; only `=` in a name and null bytes cannot be passed as `--env KEY=VALUE`.
            foreach ($environment as $key => $value) {
                if ($key === '' || str_contains($key, '=') || str_contains($key, "\0")) {
                    throw new RuntimeException("The environment variable {$key} cannot be used in a cluster application. The name cannot be empty or contain = or a null byte.");
                }
                if (str_contains($value, "\0")) {
                    throw new RuntimeException("The value of the environment variable {$key} contains a null byte.");
                }
            }

            if ($environment === ($current->environment ?? [])) {
                return $current;
            }

            return $workload->createRevision($current->image, $current->configuration ?? [], $environment);
        });
    }
}
