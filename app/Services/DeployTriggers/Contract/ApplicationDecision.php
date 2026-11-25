<?php

namespace App\Services\DeployTriggers\Contract;

final readonly class ApplicationDecision
{
    public const SKIP_REASON_CODES = ['no_match', 'watch_paths_no_match'];

    /**
     * @param  'deploy'|'skip'  $decision
     * @param  list<array<string, mixed>>  $matches
     * @param  array<string, mixed>|null  $lockfile
     * @param  array<string, mixed>|null  $triggerSet
     */
    public function __construct(
        public string $id,
        public string $decision,
        public string $reasonCode,
        public string $reason,
        public ?string $base = null,
        public array $matches = [],
        public ?array $lockfile = null,
        public ?array $triggerSet = null,
    ) {}

    public static function deployBecause(string $id, string $reasonCode, string $reason, ?string $base = null): self
    {
        return new self($id, 'deploy', $reasonCode, $reason, $base);
    }

    public function deploys(): bool
    {
        return $this->decision === 'deploy';
    }

    /**
     * @return array{reason_code: string, reason: string, base: ?string, after: ?string, matches: list<array<string, mixed>>, lockfile: ?array<string, mixed>}
     */
    public function toTriggerReason(?string $after): array
    {
        return [
            'reason_code' => $this->reasonCode,
            'reason' => $this->reason,
            'base' => $this->base,
            'after' => $after,
            'matches' => array_slice($this->matches, 0, 20),
            'lockfile' => $this->lockfile,
        ];
    }
}
