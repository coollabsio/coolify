<?php

namespace App\Services\DeployTriggers;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Services\DeployTriggers\Contract\Sha;

/**
 * The commit a push is compared with: the newest queued or running deployment, else the stored base, else the last finished deployment (spec §4.1).
 */
final class EffectiveBase
{
    /**
     * @return array{base: ?string, isStored: bool}
     */
    public static function for(Application $application, ?ApplicationPreview $preview): array
    {
        $inFlight = ApplicationDeploymentQueue::query()
            ->where('application_id', $application->id)
            ->where('pull_request_id', $preview?->pull_request_id ?? 0)
            ->whereIn('status', [ApplicationDeploymentStatus::QUEUED->value, ApplicationDeploymentStatus::IN_PROGRESS->value])
            ->where(fn ($query) => $query->where('restart_only', false)->orWhereNull('restart_only'))
            ->orderByDesc('id')
            ->value('commit');

        if ($inFlight !== null) {
            return ['base' => Sha::isValid($inFlight) ? $inFlight : null, 'isStored' => false];
        }

        $stored = ($preview ?? $application)->trigger_base_commit;
        if (Sha::isValid($stored)) {
            return ['base' => $stored, 'isStored' => true];
        }

        // No base stored yet, for example right after the upgrade: compare with the last finished deployment.
        $deployed = ApplicationDeploymentQueue::query()
            ->where('application_id', $application->id)
            ->where('pull_request_id', $preview?->pull_request_id ?? 0)
            ->where('status', ApplicationDeploymentStatus::FINISHED->value)
            ->where(fn ($query) => $query->where('restart_only', false)->orWhereNull('restart_only'))
            ->orderByDesc('id')
            ->value('commit');

        return ['base' => Sha::isValid($deployed) ? $deployed : null, 'isStored' => false];
    }
}
