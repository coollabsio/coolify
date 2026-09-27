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

            foreach ($environment as $key => $value) {
                if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $key) !== 1) {
                    throw new RuntimeException("The environment variable {$key} cannot be used in a cluster application. Use only letters, numbers, and underscores in the name.");
                }
                if (mb_strlen($value) > 4096 || str_contains($value, "\0")) {
                    throw new RuntimeException("The value of the environment variable {$key} is too long or contains a null byte.");
                }
            }
            if (count($environment) > 256) {
                throw new RuntimeException('A cluster application can use at most 256 runtime environment variables.');
            }

            if ($environment === ($current->environment ?? [])) {
                return $current;
            }

            return $workload->createRevision($current->image, $current->configuration ?? [], $environment);
        });
    }
}
