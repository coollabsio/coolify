<?php

namespace App\Services\DeployTriggers;

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Services\DeployTriggers\Contract\EvaluateRequest;

final class TriggerFingerprint
{
    /**
     * Stored trigger sets and scans are valid only while this stays the same (spec §3.5).
     */
    public static function for(Application $application, ?ApplicationPreview $preview = null): string
    {
        return hash('sha256', json_encode([
            'build_pack' => $application->build_pack,
            'base_directory' => $application->base_directory,
            'dockerfile_location' => $application->build_pack === 'dockerfile' ? $application->dockerfile_location : null,
            'docker_compose_location' => $application->build_pack === 'dockercompose' ? $application->docker_compose_location : null,
            'install_command' => $application->install_command,
            'build_command' => $application->build_command,
            'start_command' => $application->start_command,
            'docker_compose_custom_build_command' => $application->docker_compose_custom_build_command,
            'docker_compose_custom_start_command' => $application->docker_compose_custom_start_command,
            'watch_paths' => $application->watch_paths,
            'smart_triggers' => (bool) ($application->settings?->is_smart_triggers_enabled ?? true),
            'git_repository' => $application->git_repository,
            'git_branch' => $application->git_branch,
            'managed_config_paths' => $application->managedConfigPaths(),
            'pull_request_id' => $preview?->pull_request_id,
            'schema_version' => EvaluateRequest::SCHEMA_VERSION,
        ], JSON_THROW_ON_ERROR));
    }
}
