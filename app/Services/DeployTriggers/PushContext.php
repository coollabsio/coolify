<?php

namespace App\Services\DeployTriggers;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * One Git push (or pull request update) as the webhook saw it.
 */
final readonly class PushContext
{
    private const PROCESSED_STATUSES = [
        ApplicationDeploymentStatus::QUEUED->value,
        ApplicationDeploymentStatus::IN_PROGRESS->value,
        ApplicationDeploymentStatus::FINISHED->value,
        ApplicationDeploymentStatus::SKIPPED->value,
    ];

    /**
     * @param  'github'|'github_manual'|'gitlab'|'gitea'|'bitbucket'  $provider
     * @param  ?string  $after  null only for dry runs; the tool resolves the tip of $ref
     * @param  list<string>|null  $payloadChangedFiles  the webhook's own list, for the legacy fallback only
     * @param  ?int  $arrivalWatermark  the newest deployment id when the push arrived
     */
    public function __construct(
        public string $provider,
        public int $teamId,
        public string $repositoryUrl,
        public string $ref,
        public ?string $after,
        public ?string $before = null,
        public ?bool $forced = null,
        public ?string $commitMessage = null,
        public ?int $githubAppId = null,
        public ?string $repositoryFullName = null,
        public ?array $payloadChangedFiles = null,
        public ?PullRequestContext $pullRequest = null,
        public ?int $arrivalWatermark = null,
    ) {}

    public function withTeam(int $teamId): self
    {
        return new self(...[...get_object_vars($this), 'teamId' => $teamId]);
    }

    public function uniqueKey(): string
    {
        return sha1("{$this->teamId}|{$this->repositoryUrl}|{$this->ref}|".($this->after ?? 'tip'));
    }

    public function laneKey(): string
    {
        return sha1("{$this->teamId}|{$this->repositoryUrl}|{$this->ref}");
    }

    /**
     * Remembers this push as the newest of its branch for each application, and the push before it.
     *
     * @param  list<int>  $applicationIds
     */
    public function recordArrival(array $applicationIds): self
    {
        if ($this->after === null) {
            return $this;
        }

        $watermark = (int) ApplicationDeploymentQueue::query()->max('id');
        foreach ($applicationIds as $applicationId) {
            $head = $this->head($applicationId);
            $previous = $head['after'] ?? null;
            if ($previous === $this->after) {
                continue;
            }
            // A redelivered older push must not hide the push before it that was never evaluated.
            $older = $head['previous'] ?? null;
            if (is_string($previous) && is_string($older)
                && $this->deploymentsOf($applicationId, $previous)->where('id', '<=', $head['watermark'] ?? 0)->exists()
                && ! $this->deploymentsOf($applicationId, $older)->exists()) {
                $previous = $older;
            }
            Cache::put($this->headKey($applicationId), ['after' => $this->after, 'previous' => $previous, 'watermark' => $watermark], now()->addDay());
        }

        return new self(...[...get_object_vars($this), 'arrivalWatermark' => $watermark]);
    }

    /**
     * Whether a newer push replaces this one for the application: a newer evaluated push, or a deployment that a webhook queued directly.
     * A redelivered older push, already deployed or skipped when this one arrived, does not.
     */
    public function isSupersededFor(Application $application): bool
    {
        if ($this->after === null) {
            return false;
        }

        $headAfter = $this->head($application->id)['after'] ?? null;
        if (is_string($headAfter) && $headAfter !== $this->after
            && ($this->arrivalWatermark === null || ! $this->deploymentsOf($application->id, $headAfter)->where('id', '<=', $this->arrivalWatermark)->exists())) {
            return true;
        }

        return $this->arrivalWatermark !== null && $this->deploymentsOf($application->id)
            ->where('id', '>', $this->arrivalWatermark)
            ->where('commit', '!=', $this->after)
            ->where('status', '!=', ApplicationDeploymentStatus::SKIPPED->value)
            ->where('is_webhook', true)
            ->whereNull('trigger_reason')
            ->whereNull('parent_deployment_uuid')
            ->exists();
    }

    /**
     * Whether the push before this one was dropped or is still waiting, so the file list of this webhook misses its changes.
     */
    public function followsUnevaluatedPush(Application $application): bool
    {
        $head = $this->head($application->id);
        if (($head['after'] ?? null) !== $this->after) {
            return $head !== null;
        }
        $previous = $head['previous'] ?? null;

        return is_string($previous) && ! $this->deploymentsOf($application->id, $previous)->exists();
    }

    /**
     * @return array{after: string, previous: ?string, watermark: int}|null
     */
    private function head(int $applicationId): ?array
    {
        $head = Cache::get($this->headKey($applicationId));

        return is_array($head) ? $head : null;
    }

    private function headKey(int $applicationId): string
    {
        return 'deploy-triggers:lane-head:'.$this->laneKey().':'.$applicationId;
    }

    /**
     * Deployments and skips of the application that took a push.
     */
    private function deploymentsOf(int $applicationId, ?string $commit = null): Builder
    {
        return ApplicationDeploymentQueue::query()
            ->where('application_id', $applicationId)
            ->where('pull_request_id', $this->pullRequest?->number ?? 0)
            ->whereIn('status', self::PROCESSED_STATUSES)
            ->when($commit !== null, fn (Builder $query) => $query->where('commit', $commit));
    }
}
