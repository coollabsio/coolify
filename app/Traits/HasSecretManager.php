<?php

namespace App\Traits;

use App\Exceptions\RemoteSecretException;
use App\Models\EnvironmentVariable;
use App\Models\SecretManagerLink;
use App\Support\RemoteSecretReferences;
use App\Support\RemoteSecretValueFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Throwable;

trait HasSecretManager
{
    /** @var array<string, string>|null */
    private ?array $resolvedSecretManagerValues = null;

    public static function bootHasSecretManager(): void
    {
        // Return nothing: a non-null result halts the "deleting" event and skips later listeners.
        static::deleting(function ($resource): void {
            $resource->secretManagerLink()->delete();
        });
    }

    public function secretManagerLink(): MorphOne
    {
        return $this->morphOne(SecretManagerLink::class, 'resourceable');
    }

    /**
     * Give a cloned resource the same secret manager source. The token is team-scoped, so a clone in another team gets no link.
     */
    public function cloneSecretManagerLinkTo(Model $target): void
    {
        $link = $this->secretManagerLink()->with('integrationToken')->first();
        // fresh(): a replicated model keeps the relations of its source, such as the old environment.
        $targetTeamId = $target->fresh()?->team()?->id;

        if (! $link || $targetTeamId === null || (int) $link->integrationToken?->team_id !== (int) $targetTeamId) {
            return;
        }

        $target->secretManagerLink()->create([
            'integration_token_id' => $link->integration_token_id,
            'settings' => $link->settings,
        ]);
    }

    /**
     * The value for an `environment:` entry of a generated compose file (standalone databases).
     */
    public function resolveSecretManagerEnvironmentVariable(EnvironmentVariable $environmentVariable): ?string
    {
        $value = $this->resolveSecretManagerEnvironmentVariableValue($environmentVariable);

        return $this->formatEnvironmentVariableValue($environmentVariable, $value);
    }

    /**
     * The value for a line of a dotenv file that Docker Compose reads (the service .env file).
     */
    public function resolveSecretManagerDotenvValue(EnvironmentVariable $environmentVariable): ?string
    {
        $value = $this->resolveSecretManagerEnvironmentVariableValue($environmentVariable);

        if ($value !== null && $this->environmentVariableUsesSecretManager($environmentVariable)) {
            return RemoteSecretValueFormatter::dotenv($value);
        }

        return $this->formatLocalEnvironmentVariableValue($environmentVariable, $value);
    }

    /**
     * Formats a resolved value for an `environment:` entry of a generated compose file. Remote secret
     * values are used exactly as they are; other values keep their existing format.
     */
    public function formatEnvironmentVariableValue(EnvironmentVariable $environmentVariable, ?string $value): ?string
    {
        if ($value !== null && $this->environmentVariableUsesSecretManager($environmentVariable)) {
            return RemoteSecretValueFormatter::composeFile($value);
        }

        return $this->formatLocalEnvironmentVariableValue($environmentVariable, $value);
    }

    /**
     * Formats a resolved value that is placed directly in a generated compose file, such as a
     * password in a command or healthcheck. Remote secret values are escaped for compose
     * interpolation; other values stay unchanged, as before.
     */
    public function formatComposeFileValue(EnvironmentVariable $environmentVariable, string $value): string
    {
        return $this->environmentVariableUsesSecretManager($environmentVariable)
            ? RemoteSecretValueFormatter::composeFile($value)
            : $value;
    }

    /**
     * Resolves every remote secret reference of the given variables now, so that an unreachable
     * secret manager or a missing key stops an operation before it stops the running resource.
     * The fetched secrets stay cached on this model for the start that follows.
     *
     * @param  iterable<EnvironmentVariable>  $environmentVariables
     *
     * @throws RemoteSecretException
     */
    public function ensureRemoteSecretsResolvable(iterable $environmentVariables): void
    {
        foreach ($environmentVariables as $environmentVariable) {
            $this->resolveSecretManagerEnvironmentVariableValue($environmentVariable);
        }
    }

    private function formatLocalEnvironmentVariableValue(EnvironmentVariable $environmentVariable, ?string $value): ?string
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

        if ($this->resolvesRemoteSecretReferences($environmentVariable, $value)) {
            $secrets = $this->secretManagerValues();
            $missing = RemoteSecretReferences::missingKeys($value, $secrets);

            if ($missing !== []) {
                throw new RemoteSecretException('Missing secret keys: '.implode(', ', $missing)." (referenced by {$environmentVariable->key}).");
            }

            $value = RemoteSecretReferences::substitute($value, $secrets);
        }

        return $value;
    }

    public function environmentVariableUsesSecretManager(EnvironmentVariable $environmentVariable): bool
    {
        return $this->resolvesRemoteSecretReferences(
            $environmentVariable,
            $this->resolvedEnvironmentVariableValue($environmentVariable),
        );
    }

    /**
     * Without a source, a literal keeps "{{vault.KEY}}" as text; other references fail closed.
     */
    private function resolvesRemoteSecretReferences(EnvironmentVariable $environmentVariable, ?string $value): bool
    {
        if (! RemoteSecretReferences::containsReference($value)) {
            return false;
        }

        return ! $environmentVariable->is_literal
            || $this->resolvedSecretManagerValues !== null
            || $this->secretManagerLink()->exists();
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
            throw new RemoteSecretException('Environment variables reference remote secrets, but no secret manager source is configured.');
        }

        try {
            return $this->resolvedSecretManagerValues = $link->fetchSecrets();
        } catch (RemoteSecretException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RemoteSecretException('Could not fetch remote secrets: '.$e->getMessage(), previous: $e);
        }
    }

    /** @return array<string, string> */
    public function resolvedSecretManagerValuesForRedaction(): array
    {
        return $this->resolvedSecretManagerValues ?? [];
    }
}
