<?php

namespace App\Services\ProjectConfig;

/**
 * The result of comparing a file with the stored state of one environment. Holds no secret values.
 */
final readonly class ConfigPlan
{
    /**
     * @param  list<Change>  $changes
     * @param  list<ConfigError>  $errors
     * @param  list<string>  $warnings
     * @param  array<string, list<string>>  $missingRequired  resource key => variable names
     */
    public function __construct(
        public array $changes,
        public array $errors,
        public array $warnings,
        public string $stateHash,
        public array $missingRequired = [],
        public bool $removesAll = false,
    ) {}

    public function isDestructive(): bool
    {
        if ($this->removesAll) {
            return true;
        }
        foreach ($this->changes as $change) {
            if ($change->destructive) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return list<string>
     */
    public function removedKeys(): array
    {
        return array_values(array_map(
            fn (Change $change) => $change->key,
            array_filter($this->changes, fn (Change $change) => $change->kind === ChangeKind::Remove),
        ));
    }

    public function toArray(): array
    {
        return [
            'changes' => array_map(fn (Change $change) => $change->toArray(), $this->changes),
            'errors' => array_map(fn (ConfigError $error) => $error->toArray(), $this->errors),
            'warnings' => $this->warnings,
            'missing_required' => $this->missingRequired,
            'state_hash' => $this->stateHash,
            'destructive' => $this->isDestructive(),
            'removes_all' => $this->removesAll,
        ];
    }
}
