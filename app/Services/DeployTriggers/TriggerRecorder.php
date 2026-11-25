<?php

namespace App\Services\DeployTriggers;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Services\DeployTriggers\Contract\ApplicationDecision;
use App\Services\DeployTriggers\Contract\Sha;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Persists push decisions: folded skipped rows, base commits, trigger sets, and scans.
 */
class TriggerRecorder
{
    public const SKIPPED_LOG_LIMIT = 20;

    /**
     * @param  PullRequestContext|null  $pullRequest  the pull request of the push; a skip of a pull request never touches the application
     */
    public function recordSkip(Application $application, ?ApplicationPreview $preview, ApplicationDecision $decision, string $after, ?string $readBase, bool $baseIsStored, ?string $commitMessage, ?PullRequestContext $pullRequest = null): ApplicationDeploymentQueue
    {
        return DB::transaction(function () use ($application, $preview, $decision, $after, $readBase, $baseIsStored, $commitMessage, $pullRequest): ApplicationDeploymentQueue {
            $pullRequestId = $preview?->pull_request_id ?? $pullRequest?->number ?? 0;
            $baseTarget = $preview ?? ($pullRequestId > 0 ? null : $application);
            if ($baseIsStored && $baseTarget !== null) {
                $this->advanceBase($baseTarget, $readBase, $after);
            }

            $newest = ApplicationDeploymentQueue::query()
                ->where('application_id', $application->id)
                ->where('pull_request_id', $pullRequestId)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $row = $newest?->status === ApplicationDeploymentStatus::SKIPPED->value
                ? $newest
                : $this->newSkippedRow($application, $pullRequestId, $after, $preview?->git_type ?? $pullRequest?->gitType);

            $row->forceFill([
                'commit' => $after,
                'commit_message' => $commitMessage ?? $row->commit_message,
                'trigger_reason' => [...$decision->toTriggerReason($after), 'pull_request_html_url' => $preview?->pull_request_html_url ?? $pullRequest?->htmlUrl],
                'skipped_count' => ($row->skipped_count ?? 0) + 1,
                'first_skipped_commit' => $row->first_skipped_commit ?? $after,
                'finished_at' => Carbon::now(),
            ])->save();

            $row->addLogEntry("Skipped {$after}: {$decision->reason}");
            $this->trimLogs($row);

            return $row->refresh();
        });
    }

    /**
     * @param  array<string, mixed>|null  $triggerSet  null keeps the stored set (a failed evaluation)
     */
    public function storeTriggerSet(Application $application, ?ApplicationPreview $preview, ?array $triggerSet, string $evaluatedCommit, string $configFingerprint, ?string $lastError = null, bool $dryRun = false): void
    {
        $target = $preview ?? $application;
        $set = $triggerSet ?? ($target->trigger_set ?? []);
        if ($triggerSet !== null) {
            $set['evaluated_commit'] = $evaluatedCommit;
            $set['config_fingerprint'] = $configFingerprint;
        }
        $set['evaluated_at'] = Carbon::now()->toIso8601String();
        $set['last_error'] = $lastError;
        $set['last_error_dry_run'] = $lastError !== null && $dryRun;

        $target->forceFill(['trigger_set' => $set])->saveQuietly();
    }

    /**
     * The base moves to the deployed commit once every destination of the deployment finished.
     */
    public function recordSuccessfulDeployment(ApplicationDeploymentQueue $deployment): void
    {
        $parentUuid = $deployment->parent_deployment_uuid ?? $deployment->deployment_uuid;
        $group = ApplicationDeploymentQueue::query()
            ->where(fn ($query) => $query->where('deployment_uuid', $parentUuid)->orWhere('parent_deployment_uuid', $parentUuid))
            ->get(['status', 'commit', 'restart_only', 'pull_request_id', 'application_id', 'deployment_uuid']);
        $parent = $group->firstWhere('deployment_uuid', $parentUuid) ?? $deployment;

        if ($parent->restart_only || ! Sha::isValid($parent->commit)
            || $group->contains(fn (ApplicationDeploymentQueue $row): bool => $row->status !== ApplicationDeploymentStatus::FINISHED->value)) {
            return;
        }

        $target = (int) $parent->pull_request_id === 0
            ? Application::query()->withoutGlobalScopes()->find($parent->application_id)
            : ApplicationPreview::query()->where('application_id', $parent->application_id)->where('pull_request_id', $parent->pull_request_id)->first();

        $target?->forceFill(['trigger_base_commit' => $parent->commit])->saveQuietly();
    }

    private function advanceBase(Application|ApplicationPreview $target, ?string $readBase, string $after): void
    {
        $locked = $target::query()->withoutGlobalScopes()->whereKey($target->getKey())->lockForUpdate()->first();
        if (! $locked || $locked->trigger_base_commit !== $readBase) {
            return;
        }

        $locked->forceFill(['trigger_base_commit' => $after])->saveQuietly();
        $target->setRawAttributes($locked->getAttributes(), sync: true);
    }

    private function newSkippedRow(Application $application, int $pullRequestId, string $after, ?string $gitType): ApplicationDeploymentQueue
    {
        $uuid = new_public_id();
        $server = $application->destination?->server;

        return ApplicationDeploymentQueue::create([
            'application_id' => $application->id,
            'application_name' => $application->name,
            'deployment_uuid' => $uuid,
            'deployment_url' => parse_url($application->link()."/deployment/{$uuid}", PHP_URL_PATH),
            'pull_request_id' => $pullRequestId,
            'server_id' => $server?->id,
            'server_name' => $server?->name,
            'destination_id' => $application->destination?->id,
            'status' => ApplicationDeploymentStatus::SKIPPED->value,
            'is_webhook' => true,
            'commit' => $after,
            'git_type' => $gitType,
        ]);
    }

    private function trimLogs(Model $row): void
    {
        $logs = json_decode((string) $row->logs, true);
        if (! is_array($logs) || count($logs) <= self::SKIPPED_LOG_LIMIT) {
            return;
        }

        $kept = array_values(array_slice($logs, -self::SKIPPED_LOG_LIMIT));
        foreach ($kept as $index => $entry) {
            $kept[$index]['order'] = $index + 1;
        }
        ApplicationDeploymentQueue::query()->whereKey($row->getKey())->update(['logs' => json_encode($kept, JSON_THROW_ON_ERROR)]);
    }
}
