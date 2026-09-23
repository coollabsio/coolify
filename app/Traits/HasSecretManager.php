<?php

namespace App\Traits;

use App\Models\EnvironmentVariable;
use App\Models\SecretManagerLink;
use App\Support\RemoteSecretReferences;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use RuntimeException;

trait HasSecretManager
{
    /** @var array<string, string>|null */
    private ?array $resolvedSecretManagerValues = null;

    public static function bootHasSecretManager(): void
    {
        static::deleting(fn ($resource) => $resource->secretManagerLink()->delete());
    }

    public function secretManagerLink(): MorphOne
    {
        return $this->morphOne(SecretManagerLink::class, 'resourceable');
    }

    public function resolveSecretManagerEnvironmentVariable(EnvironmentVariable $environmentVariable): ?string
    {
        $value = $this->resolveSecretManagerEnvironmentVariableValue($environmentVariable);

        return $this->formatEnvironmentVariableValue($environmentVariable, $value);
    }

    public function formatEnvironmentVariableValue(EnvironmentVariable $environmentVariable, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (json_validate($value) && (str_starts_with($value, '{') || str_starts_with($value, '['))) {
            return $value;
        }

        return $environmentVariable->is_literal || $environmentVariable->is_multiline
            ? "'{$value}'"
            : escapeEnvVariables($value);
    }

    public function resolveSecretManagerEnvironmentVariableValue(EnvironmentVariable $environmentVariable): ?string
    {
        $value = $this->resolvedEnvironmentVariableValue($environmentVariable);

        if ($value === null) {
            return null;
        }

        if (RemoteSecretReferences::containsReference($value)) {
            $secrets = $this->secretManagerValues();
            $missing = RemoteSecretReferences::missingKeys($value, $secrets);

            if ($missing !== []) {
                throw new RuntimeException('Missing secret keys: '.implode(', ', $missing)." (referenced by {$environmentVariable->key}).");
            }

            $value = RemoteSecretReferences::substitute($value, $secrets);
        }

        return $value;
    }

    public function environmentVariableUsesSecretManager(EnvironmentVariable $environmentVariable): bool
    {
        return RemoteSecretReferences::containsReference(
            $this->resolvedEnvironmentVariableValue($environmentVariable),
        );
    }

    private function resolvedEnvironmentVariableValue(EnvironmentVariable $environmentVariable): ?string
    {
        return $environmentVariable->get_real_environment_variables_with_server(
            $environmentVariable->value,
            $this,
            data_get($this, 'server'),
        );
    }

    /** @return array<string, string> */
    private function secretManagerValues(): array
    {
        if ($this->resolvedSecretManagerValues !== null) {
            return $this->resolvedSecretManagerValues;
        }

        $link = $this->secretManagerLink()->with('integrationToken')->first();

        if (! $link) {
            throw new RuntimeException('Environment variables reference remote secrets, but no secret manager source is configured.');
        }

        return $this->resolvedSecretManagerValues = $link->fetchSecrets();
    }

    /** @return array<string, string> */
    public function resolvedSecretManagerValuesForRedaction(): array
    {
        return $this->resolvedSecretManagerValues ?? [];
    }
}
