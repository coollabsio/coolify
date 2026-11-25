<?php

namespace App\Services\DeployTriggers\Contract;

final readonly class EvaluateRequest
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param  array<string, string>  $gitEnv
     * @param  list<string>  $gitConfig
     * @param  list<string>  $fetchRefs
     * @param  list<EvaluateApplicationInput>  $applications
     * @param  array<string, int>|null  $limits
     */
    public function __construct(
        public string $repositoryUrl,
        public string $mirrorKey,
        public array $gitEnv,
        public array $gitConfig,
        public ?string $after,
        public array $fetchRefs,
        public bool $isFork,
        public bool $submodules,
        public array $applications,
        public ?array $limits = null,
    ) {}

    public static function mirrorKeyFor(int $teamId, string $repositoryUrl): string
    {
        return hash('sha256', $teamId.'|'.$repositoryUrl);
    }

    public const DEFAULT_MAX_MIRROR_BYTES = 2147483648;

    /**
     * @return array<string, int>
     */
    public static function defaultLimits(int $maxMirrorBytes = self::DEFAULT_MAX_MIRROR_BYTES): array
    {
        return [
            'timeout_seconds' => 120,
            'max_changed_files' => 50000,
            'max_manifest_files' => 5000,
            'max_manifest_bytes' => 52428800,
            'max_mirror_bytes' => $maxMirrorBytes,
            'max_reported_files' => 200,
        ];
    }

    /**
     * @return list<string>
     */
    public function applicationIds(): array
    {
        return array_map(fn (EvaluateApplicationInput $input): string => $input->id, $this->applications);
    }

    public function toJson(): string
    {
        return json_encode([
            'schema_version' => self::SCHEMA_VERSION,
            'repository' => [
                'url' => $this->repositoryUrl,
                'mirror_key' => $this->mirrorKey,
                'git_env' => (object) $this->gitEnv,
                'git_config' => $this->gitConfig,
                'submodules' => $this->submodules,
            ],
            'after' => $this->after,
            'fetch_refs' => $this->fetchRefs,
            'is_fork' => $this->isFork,
            'applications' => array_map(fn (EvaluateApplicationInput $input): array => $input->toArray(), $this->applications),
            'limits' => $this->limits ?? self::defaultLimits(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
