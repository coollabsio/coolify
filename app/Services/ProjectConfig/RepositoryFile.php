<?php

namespace App\Services\ProjectConfig;

final readonly class RepositoryFile
{
    public function __construct(
        public ?string $contents,
        public string $commitSha,
        public bool $missing,
    ) {}
}
