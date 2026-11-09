<?php

namespace App\Services\ProjectConfig;

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\EnvironmentConfigLink;

/**
 * Tells the user when a deployed repository has a coolify.json that no environment link uses.
 */
class UnlinkedConfigFileHint
{
    public const MESSAGE = 'coolify.json found, not linked. Export this environment and link it to start using the file.';

    public function shouldCheck(Application $application, int $pullRequestId): bool
    {
        return $pullRequestId === 0
            && $application->config_key === null
            && ! EnvironmentConfigLink::query()->where('environment_id', $application->environment_id)->exists();
    }

    public function command(string $deploymentUuid, string $repositoryDirectory): string
    {
        return executeInDocker($deploymentUuid, 'test -f '.escapeshellarg(rtrim($repositoryDirectory, '/').'/coolify.json')." && echo 'exists' || echo 'missing'");
    }

    public function record(Application $application, ApplicationDeploymentQueue $deployment, ?string $output): void
    {
        $found = trim((string) $output) === 'exists';
        if ($found) {
            $deployment->addLogEntry(self::MESSAGE);
        }
        $settings = $application->settings;
        if ($settings !== null && (bool) $settings->has_unlinked_config_file !== $found) {
            $settings->forceFill(['has_unlinked_config_file' => $found])->saveQuietly();
        }
    }
}
