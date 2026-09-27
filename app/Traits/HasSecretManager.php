<?php

namespace App\Traits;

use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\SecretManagerLink;
use App\Models\Service;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneRedis;
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

    /**
     * Format a value for a KEY=value item in a Docker Compose `environment:` list.
     * Compose keeps quotes and backslashes there, so only `$` needs escaping.
     */
    public function formatEnvironmentVariableValue(EnvironmentVariable $environmentVariable, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! $this->useExactEscaping($environmentVariable)) {
            return $this->legacyFormatEnvironmentVariableValue($environmentVariable, $value);
        }

        return $this->environmentVariableAllowsInterpolation($environmentVariable, $value)
            ? $value
            : escapeDollarSign($value);
    }

    /**
     * Decides the escaping of a variable for all paths (service .env, database environment,
     * Redis password). A variable saved before exact escaping keeps the old escaping, so its
     * container gets the same value as before the upgrade. Saving the variable again switches
     * it to exact escaping.
     */
    public function useExactEscaping(EnvironmentVariable $environmentVariable): bool
    {
        return ! $environmentVariable->uses_legacy_escaping;
    }

    /**
     * A variable value for a Docker Compose command or healthcheck. Compose interpolates `$` there
     * too, so the value gets the same `$` escaping as in the environment list, and the command uses
     * the same value as the container.
     */
    public function composeCommandValue(EnvironmentVariable $environmentVariable, string $value): string
    {
        if (! $this->useExactEscaping($environmentVariable) || $this->environmentVariableAllowsInterpolation($environmentVariable, $value)) {
            return $value;
        }

        return escapeDollarSign($value);
    }

    /**
     * The escaping used before exact escaping. Variables saved before the upgrade keep it,
     * so their containers get the same values as before.
     */
    public function legacyFormatEnvironmentVariableValue(EnvironmentVariable $environmentVariable, string $value): string
    {
        if (json_validate($value) && (str_starts_with($value, '{') || str_starts_with($value, '['))) {
            return $value;
        }

        return $environmentVariable->is_literal || $environmentVariable->is_multiline
            ? "'{$value}'"
            : escapeEnvVariables($value);
    }

    /**
     * Whether the container gets a different value than the saved value, because this variable
     * uses the old escaping. Secret manager values are not fetched here, so they never show it.
     */
    public function legacyEscapingChangesValue(EnvironmentVariable $environmentVariable): bool
    {
        if ($this->useExactEscaping($environmentVariable)) {
            return false;
        }

        $value = $this->resolvedEnvironmentVariableValue($environmentVariable);

        if (blank($value) || RemoteSecretReferences::containsReference($value)) {
            return false;
        }

        $isRedisPassword = $environmentVariable->key === 'REDIS_PASSWORD'
            && ($this instanceof StandaloneRedis || $this instanceof StandaloneKeydb || $this instanceof StandaloneDragonfly);

        if ($isRedisPassword && str_contains($value, '$')) {
            return true;
        }

        if (json_validate($value) && (str_starts_with($value, '{') || str_starts_with($value, '['))) {
            return str_contains($value, '$') || (! $this instanceof Application && str_contains($value, "\n"));
        }

        if ($environmentVariable->is_literal || $environmentVariable->is_multiline) {
            // Service and application .env files strip the old single quotes; database environment lists keep them.
            return ($this instanceof Service || $this instanceof Application) ? str_contains($value, "'") : true;
        }

        return escapeEnvVariables($value) !== $value
            || (($this instanceof Service || $this instanceof Application) && str_contains($value, ' #'));
    }

    /**
     * Only plain values allow Compose $VAR interpolation.
     */
    public function environmentVariableAllowsInterpolation(EnvironmentVariable $environmentVariable, string $value): bool
    {
        $isJson = json_validate($value) && in_array(ltrim($value)[0] ?? '', ['{', '['], true);

        return ! $isJson
            && ! $environmentVariable->is_literal
            && ! $environmentVariable->is_multiline
            && ! $this->environmentVariableUsesSecretManager($environmentVariable);
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
