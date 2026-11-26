<?php

namespace App\Http\Controllers\Webhook\Bitbucket;

class BitbucketWebhookContext
{
    /**
     * @param  array<int, string>  $repositoryIdentifiers
     * @param  array<string, array{commit: ?string, skip_deploy: bool}>  $pushedBranches  Every branch that a push creates or updates, keyed by branch name.
     * @param  mixed  $sourceRepository  Raw id of the pull request source repository, for the fork check.
     * @param  mixed  $targetRepository  Raw id of the pull request target repository, for the fork check.
     * @param  mixed  $webhookRepository  Raw id of the repository that sent the webhook, for the fork check.
     */
    public function __construct(
        public readonly string $rawEvent,
        public readonly string $action,
        public readonly string $branch,
        public readonly array $repositoryIdentifiers,
        public readonly ?string $commit = null,
        public readonly array $pushedBranches = [],
        public readonly bool $skipDeployPr = false,
        public readonly ?int $pullRequestId = null,
        public readonly ?string $pullRequestHtmlUrl = null,
        public readonly mixed $sourceRepository = null,
        public readonly mixed $targetRepository = null,
        public readonly mixed $webhookRepository = null,
    ) {}

    /**
     * The context of a push. The first pushed branch is the branch of the context.
     *
     * @param  array<int, string>  $repositoryIdentifiers
     * @param  array<string, array{commit: ?string, skip_deploy: bool}>  $pushedBranches
     */
    public static function push(string $rawEvent, array $repositoryIdentifiers, array $pushedBranches): ?self
    {
        $branch = array_key_first($pushedBranches);
        if ($branch === null) {
            return null;
        }

        return new self(
            rawEvent: $rawEvent,
            action: 'push',
            branch: (string) $branch,
            repositoryIdentifiers: $repositoryIdentifiers,
            commit: $pushedBranches[$branch]['commit'],
            pushedBranches: $pushedBranches,
        );
    }

    public function repositoryLabel(): string
    {
        return implode(', ', $this->repositoryIdentifiers) ?: 'unknown';
    }
}
