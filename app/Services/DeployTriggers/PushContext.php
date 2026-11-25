<?php

namespace App\Services\DeployTriggers;

use Illuminate\Support\Facades\Cache;

/**
 * One Git push (or pull request update) as the webhook saw it.
 */
final readonly class PushContext
{
    /**
     * @param  'github'|'github_manual'|'gitlab'|'gitea'|'bitbucket'  $provider
     * @param  ?string  $after  null only for dry runs; the tool resolves the tip of $ref
     * @param  list<string>|null  $payloadChangedFiles  the webhook's own list, for the legacy fallback only
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
     * Remembers this push as the newest of its branch. Webhooks arrive in push order, evaluations may not run in it.
     */
    public function recordArrival(): void
    {
        if ($this->after !== null) {
            Cache::put('deploy-triggers:lane-head:'.$this->laneKey(), $this->after, now()->addDay());
        }
    }

    /**
     * Whether a newer push of the same branch arrived after this one. Its evaluation covers this push.
     */
    public function isSuperseded(): bool
    {
        $head = Cache::get('deploy-triggers:lane-head:'.$this->laneKey());

        return is_string($head) && $this->after !== null && $head !== $this->after;
    }
}
