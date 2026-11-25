<?php

namespace App\Services\DeployTriggers\Contract;

final readonly class ScanRequest
{
    public function __construct(
        public string $worktree,
        public string $commit,
        public EvaluateApplicationInput $application,
    ) {}

    public function toJson(): string
    {
        return json_encode([
            'schema_version' => EvaluateRequest::SCHEMA_VERSION,
            'worktree' => $this->worktree,
            'commit' => $this->commit,
            'application' => $this->application->toArray(withBase: false),
            'limits' => ['timeout_seconds' => 60, 'max_files' => 100000, 'max_file_bytes' => 1048576],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
