<?php

namespace App\Services\DeployTriggers;

final readonly class PullRequestContext
{
    public function __construct(
        public int $number,
        public string $targetBranch,
        public bool $isFork,
        public string $gitType,
        public ?string $htmlUrl = null,
        public ?string $title = null,
    ) {}
}
