<?php

namespace App\Http\Controllers\Webhook\Bitbucket;

class BitbucketWebhookContext
{
    /**
     * @param  array<int, string>  $repositoryIdentifiers
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
    ) {}

    public function repositoryLabel(): string
    {
        return implode(', ', $this->repositoryIdentifiers) ?: 'unknown';
    }
}
