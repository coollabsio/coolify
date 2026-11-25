<?php

namespace App\Services\DeployTriggers;

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\InstanceSettings;
use App\Services\DeployTriggers\Contract\EvaluateApplicationInput;
use App\Services\DeployTriggers\Contract\ScanRequest;
use App\Services\DeployTriggers\Contract\ScanResponse;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs `coolify-affected scan` in the checkout of a deployment and stores which other paths the application references.
 */
class DeploymentTriggerScan
{
    public function __construct(
        private readonly HelperCapability $capability,
        private readonly TriggerRecorder $recorder,
    ) {}

    /**
     * @param  Closure(string): ?string  $execute  runs the command in the deployment's helper container and returns stdout
     */
    public function run(Application $application, ?ApplicationPreview $preview, string $worktree, string $commit, Closure $execute): void
    {
        $server = $application->destination?->server;
        if ($preview?->is_fork_pull_request || $server === null
            || ! $this->usesSmartTriggers($application)
            || ! $this->capability->compatible($server)) {
            return;
        }

        try {
            $scan = ScanResponse::fromJson((string) $execute($this->command($application, $worktree, $commit)));
            $this->recorder->storeScan($application, $preview, $scan, TriggerFingerprint::for($application, $preview));
        } catch (Throwable $exception) {
            Log::info('The trigger scan of a deployment failed; the next push deploys.', [
                'application_uuid' => $application->uuid,
                'error' => mb_substr($exception->getMessage(), 0, 300),
            ]);
        }
    }

    /**
     * References only matter for smart mode; watch paths replace detection.
     */
    private function usesSmartTriggers(Application $application): bool
    {
        return blank($application->watch_paths)
            && (bool) ($application->settings?->is_smart_triggers_enabled ?? true)
            && (bool) (InstanceSettings::query()->whereKey(0)->value('is_smart_triggers_enabled') ?? true)
            && $application->isNarrowable();
    }

    private function command(Application $application, string $worktree, string $commit): string
    {
        $input = new EvaluateApplicationInput(
            id: $application->uuid,
            baseCommit: null,
            mergeBaseRef: null,
            mode: filled($application->watch_paths) ? 'watch_paths' : 'smart',
            watchPaths: $application->watch_paths,
            buildPack: (string) $application->build_pack,
            baseDirectory: $application->base_directory ?: '/',
            dockerfileLocation: $application->build_pack === 'dockerfile' ? $application->dockerfile_location : null,
            dockerComposeLocation: $application->build_pack === 'dockercompose' ? $application->docker_compose_location : null,
            commands: [
                'install' => $application->install_command,
                'build' => $application->build_command,
                'start' => $application->start_command,
                'compose_build' => $application->docker_compose_custom_build_command,
                'compose_start' => $application->docker_compose_custom_start_command,
            ],
            referencePaths: [],
            managedConfigPaths: $application->managedConfigPaths(),
        );

        return "printf %s '".base64_encode((new ScanRequest($worktree, $commit, $input))->toJson())."' | base64 -d | coolify-affected scan";
    }
}
