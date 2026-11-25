<?php

namespace App\Services\DeploymentConfiguration;

class ConfigurationDiff
{
    /**
     * @param  array<int, array<string, mixed>>  $changes
     */
    public function __construct(
        protected array $changes = [],
        protected bool $legacyFallback = false,
    ) {}

    public static function unchanged(): self
    {
        return new self;
    }

    /**
     * The last deployment stored no configuration snapshot (it ran before v4.1), so the
     * deployed configuration is unknown. Report no changes, but build on the next deployment.
     */
    public static function legacy(): self
    {
        return new self(legacyFallback: true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $changes
     */
    public static function fromChanges(array $changes): self
    {
        return new self(array_values($changes));
    }

    public function isChanged(): bool
    {
        return $this->changes !== [];
    }

    public function isLegacyFallback(): bool
    {
        return $this->legacyFallback;
    }

    public function count(): int
    {
        return count($this->changes);
    }

    public function requiresBuild(): bool
    {
        return $this->legacyFallback || collect($this->changes)->contains(fn (array $change): bool => $change['impact'] === 'build');
    }

    public function requiresRedeploy(): bool
    {
        return $this->isChanged();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function changes(): array
    {
        return $this->changes;
    }

    /**
     * @return array{changed: bool, count: int, requires_build: bool, requires_redeploy: bool, legacy_fallback: bool, changes: array<int, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'changed' => $this->isChanged(),
            'count' => $this->count(),
            'requires_build' => $this->requiresBuild(),
            'requires_redeploy' => $this->requiresRedeploy(),
            'legacy_fallback' => $this->isLegacyFallback(),
            'changes' => $this->changes(),
        ];
    }
}
