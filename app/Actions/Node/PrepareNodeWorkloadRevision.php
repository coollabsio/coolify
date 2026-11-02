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
     * Return the revision to deploy. Environment variables, volumes, and the health check are
     * edited on the workload, so a new revision records them when they changed.
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

            $secrets = $workload->runtimeSecrets();
            // Sentinel mounts each secret as /run/secrets/<key>, and Podman stores 1 byte to 512 kB.
            foreach ($secrets as $key => $value) {
                if (preg_match('/\A(?!\.{1,2}\z)[A-Za-z0-9_.-]{1,255}\z/', (string) $key) !== 1) {
                    throw new RuntimeException("The secret file {$key} cannot be used. A file name has 1 to 255 letters, digits, _, . or -.");
                }
                if ($value === '' || strlen($value) > 512000 || str_contains($value, "\0")) {
                    throw new RuntimeException("The secret file {$key} must have a value of 1 byte to 512 kB without null bytes.");
                }
            }
            if (count($secrets) > 128) {
                throw new RuntimeException('A cluster application can have at most 128 secret files.');
            }

            $configuration = $workload->withWorkloadSettings($current->configuration ?? []);
            if ($environment === ($current->environment ?? []) && $secrets === ($current->secrets ?? []) && $configuration === ($current->configuration ?? [])) {
                return $current;
            }

            return $workload->createRevision($current->image, $configuration, $environment, $secrets);
        });
    }
}
