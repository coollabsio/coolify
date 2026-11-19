<?php

namespace App\Http\Controllers\Webhook\Bitbucket;

class BitbucketWebhookContext
{
    /**
     * @param  array<int, string>  $repositoryIdentifiers
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
        public readonly bool $skipDeployCommits = false,
        public readonly bool $skipDeployPr = false,
        public readonly ?int $pullRequestId = null,
        public readonly ?string $pullRequestHtmlUrl = null,
        public readonly mixed $sourceRepository = null,
        public readonly mixed $targetRepository = null,
        public readonly mixed $webhookRepository = null,
    ) {}

    public function repositoryLabel(): string
    {
        return implode(', ', $this->repositoryIdentifiers) ?: 'unknown';
    }
}
