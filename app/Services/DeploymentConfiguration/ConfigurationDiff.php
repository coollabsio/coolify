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

    public static function legacy(bool $changed): self
    {
        if (! $changed) {
            return self::unchanged();
        }

        return new self([
            [
                'key' => 'legacy.configuration',
                'section' => 'configuration',
                'section_label' => 'Configuration',
                'label' => 'Configuration',
                'type' => 'changed',
                'impact' => 'build',
                'sensitive' => false,
                'old_display_value' => 'Previously deployed configuration',
                'new_display_value' => 'Current configuration',
            ],
        ], true);
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
        return collect($this->changes)->contains(fn (array $change): bool => $change['impact'] === 'build');
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
