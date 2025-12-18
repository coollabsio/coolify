<?php

namespace App\Actions\Application;

use App\Models\Application;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class CleanupOldDeployments
{
    use AsAction;

    public const DEFAULT_RETENTION = 5;

    public function handle(Application $application, Server $server, int $retention = self::DEFAULT_RETENTION): array
    {
        $deploymentsBaseDir = deployments_base_dir($application->uuid);
        $cleanupLog = [];

        // List all deployment directories sorted by modification time (newest first)
        $listCommand = "ls -1t {$deploymentsBaseDir} 2>/dev/null || true";
        $output = instant_remote_process([$listCommand], $server, throwError: false);

        if (empty(trim($output))) {
            return $cleanupLog;
        }

        $deployments = collect(explode("\n", trim($output)))->filter();

        // Skip if we have fewer deployments than retention limit
        if ($deployments->count() <= $retention) {
            return $cleanupLog;
        }

        // Get deployments to delete (skip first N based on retention)
        $deploymentsToDelete = $deployments->skip($retention);

        foreach ($deploymentsToDelete as $deploymentUuid) {
            $deploymentDir = "{$deploymentsBaseDir}/{$deploymentUuid}";

            // Read metadata to get image name for potential cleanup
            $metadataCommand = "cat {$deploymentDir}/metadata.json 2>/dev/null || echo '{}'";
            $metadataJson = instant_remote_process([$metadataCommand], $server, throwError: false);
            $metadata = json_decode($metadataJson, true) ?? [];

            // Delete deployment directory
            $deleteCommand = "rm -rf {$deploymentDir}";
            instant_remote_process([$deleteCommand], $server, throwError: false);
            $cleanupLog[] = "Deleted deployment directory: {$deploymentDir}";

            // Optionally clean up the Docker image if it exists and is not used by remaining deployments
            if (isset($metadata['image_name'])) {
                $imageName = $metadata['image_name'];

                // Check if this image is still referenced by other deployments
                $checkImageUsage = "grep -r '\"image_name\": \"{$imageName}\"' {$deploymentsBaseDir}/*/metadata.json 2>/dev/null | wc -l";
                $usageCount = (int) trim(instant_remote_process([$checkImageUsage], $server, throwError: false));

                if ($usageCount === 0) {
                    instant_remote_process([
                        "docker rmi {$imageName} 2>/dev/null || true",
                    ], $server, throwError: false);
                    $cleanupLog[] = "Removed unused image: {$imageName}";
                }
            }
        }

        return $cleanupLog;
    }
}
