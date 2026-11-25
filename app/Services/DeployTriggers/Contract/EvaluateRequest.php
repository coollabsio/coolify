<?php

namespace App\Services\DeployTriggers\Contract;

final readonly class EvaluateRequest
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param  list<string>  $fetchRefs
     * @param  list<EvaluateApplicationInput>  $applications
     * @param  array<string, int>|null  $limits
     */
    public function __construct(
        public string $repositoryUrl,
        public string $mirrorKey,
        public ?string $after,
        public array $fetchRefs,
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
            'max_changed_files' => 50000,
            'max_mirror_bytes' => $maxMirrorBytes,
        ];
    }

    /**
     * @return list<string>
     */
    public function applicationIds(): array
    {
        return array_map(fn (EvaluateApplicationInput $input): string => $input->id, $this->applications);
    }
}
