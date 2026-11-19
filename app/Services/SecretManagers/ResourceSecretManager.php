<?php

namespace App\Services\SecretManagers;

use App\Models\IntegrationToken;
use App\Models\SecretManagerLink;
use App\Support\ValidationPatterns;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The secret manager source of an application, service, or database, and its {{vault.KEY}} reference variables.
 * Only tokens of the resource's team can be linked. Secret values are read into memory only to list or import
 * key names; they are never stored or returned. The resource page and the API use this service.
 */
class ResourceSecretManager
{
    /**
     * Secret manager tokens of the resource's team that have the secrets capability. Without write access on
     * the whole team, only the linked token: such a user cannot link another token.
     *
     * @return Collection<int, IntegrationToken>
     */
    public function availableTokens(Model $resource): Collection
    {
        $tokens = $this->teamTokens($resource);
        if (! Gate::allows('useTeamCredentials', $resource)) {
            $tokens->whereIn('id', $resource->secretManagerLink()->select('integration_token_id'));
        }

        return $tokens->get()
            ->filter(fn (IntegrationToken $token): bool => $token->hasCapability('secrets'))
            ->values();
    }

    public function findToken(Model $resource, string $uuid): ?IntegrationToken
    {
        $token = $this->teamTokens($resource)->where('uuid', $uuid)->first();

        return $token?->hasCapability('secrets') ? $token : null;
    }

    /**
     * Rules for the provider settings of a link to the token.
     *
     * @return array<string, array<int, string>>
     */
    public function settingsRules(IntegrationToken $token): array
    {
        return match ($token->provider) {
            'doppler' => $token->dopplerTokenType() === 'service_account'
                ? [
                    'settings.project' => ['required', 'string'],
                    'settings.config' => ['required', 'string'],
                ]
                : [],
            'infisical' => [
                'settings.project_id' => ['required', 'string'],
                'settings.environment' => ['required', 'string'],
                'settings.secret_path' => ['nullable', 'string'],
            ],
            'vault' => [
                'settings.mount' => ['required', 'string'],
                'settings.path' => ['required', 'string'],
            ],
            default => [],
        };
    }

    /**
     * Links the token as the source of the resource, or changes the token or settings of the existing link.
     * Both need write access on the whole team: the token and its settings decide which secrets the resource reads.
     *
     * @param  array<string, mixed>  $settings
     *
     * @throws AuthorizationException
     */
    public function link(Model $resource, IntegrationToken $token, array $settings, string $auditSource): SecretManagerLink
    {
        Gate::authorize('useTeamCredentials', $resource);
        $settings = array_filter($settings, fn ($value) => filled($value));
        $link = $resource->secretManagerLink()->updateOrCreate([], [
            'integration_token_id' => $token->id,
            'settings' => $settings ?: null,
        ]);
        $this->audit($auditSource, $resource, 'source_updated', [
            'integration_token_uuid' => $token->uuid,
            'provider' => $token->provider,
        ]);

        return $link;
    }

    /**
     * Removes the source. Existing {{vault.*}} variables stay and fail the next deployment until they are removed.
     */
    public function unlink(Model $resource, string $auditSource): void
    {
        $token = $resource->secretManagerLink()->with('integrationToken')->first()?->integrationToken;
        $resource->secretManagerLink()->delete();
        $this->audit($auditSource, $resource, 'source_removed', [
            'integration_token_uuid' => $token?->uuid,
            'provider' => $token?->provider,
        ]);
    }

    /**
     * Key names of the remote secrets, sorted. The values are discarded.
     *
     * @return list<string>
     */
    public function keyNames(Model $resource, SecretManagerLink $link, string $auditSource): array
    {
        $keys = array_map('strval', array_keys($link->fetchSecrets()));
        sort($keys);
        $this->audit($auditSource, $resource, 'keys_viewed', ['key_count' => count($keys)]);

        return $keys;
    }

    /**
     * Adds a {{vault.KEY}} variable for one remote key.
     *
     * @throws ValidationException when the key is not a valid variable name or a variable with the key exists
     */
    public function addReference(Model $resource, string $key, string $auditSource): void
    {
        if (! ValidationPatterns::isValidEnvironmentVariableKey($key)) {
            throw ValidationException::withMessages(['key' => "{$key} is not a valid variable name."]);
        }
        if ($resource->environment_variables()->where('key', $key)->exists()) {
            throw ValidationException::withMessages(['key' => "A variable with the key {$key} already exists."]);
        }

        $resource->environment_variables()->create(['key' => $key, 'value' => '{{vault.'.$key.'}}']);
        $this->audit($auditSource, $resource, 'reference_created', ['secret_key' => $key]);
    }

    /**
     * Adds a {{vault.KEY}} variable for every remote key that has no variable yet.
     *
     * @return array{imported: list<string>, skipped: list<string>}
     */
    public function importAll(Model $resource, SecretManagerLink $link, string $auditSource): array
    {
        $result = $link->importMissingReferences();
        $this->audit($auditSource, $resource, 'references_imported', [
            'key_count' => count($result['imported']),
            'secret_keys' => $result['imported'],
            'skipped_key_count' => count($result['skipped']),
        ]);

        return $result;
    }

    /** @param array<string, mixed> $context */
    public function audit(string $source, Model $resource, string $action, array $context = []): void
    {
        $resourceType = str(class_basename($resource))->snake()->value();

        auditLog("{$source}.{$resourceType}.secret_manager.{$action}", array_merge([
            'team_id' => $resource->team()?->id,
            "{$resourceType}_uuid" => $resource->uuid,
            "{$resourceType}_name" => $resource->name,
        ], $context));
    }

    /**
     * Secret manager tokens of the resource's team. The session team can differ from it.
     */
    private function teamTokens(Model $resource): Builder
    {
        return IntegrationToken::query()
            ->where('team_id', $resource->team()?->id)
            ->whereIn('provider', IntegrationToken::SECRET_MANAGER_PROVIDERS);
    }
}
