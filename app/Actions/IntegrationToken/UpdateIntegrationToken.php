<?php

namespace App\Actions\IntegrationToken;

use App\Models\IntegrationToken;
use App\Services\Dns\CloudflareDnsProvider;
use App\Services\IntegrationTokenValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Updates an integration token. The provider checks the token again when the secret, the capabilities, or the
 * settings change; a Cloudflare token gets its zones again when the secret or the capabilities change.
 * The security page and the API use this action.
 */
class UpdateIntegrationToken
{
    use AsAction;

    public function __construct(
        private IntegrationTokenValidator $validator,
        private CloudflareDnsProvider $cloudflare,
    ) {}

    /**
     * Validation rules for an update. `token` is the new secret and is optional.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(string $provider): array
    {
        $rules = CreateIntegrationToken::rules($provider);
        unset($rules['provider']);
        $rules['token'][0] = 'nullable';

        return $rules;
    }

    /**
     * @param  array<int, string>  $capabilities
     * @param  array<string, mixed>  $metadata  The complete provider settings; empty values are dropped.
     *
     * @throws ValidationException when a connection setting changes without a new secret
     * @throws RuntimeException when the provider rejects the token or Cloudflare zones cannot be read
     */
    public function handle(
        IntegrationToken $integrationToken,
        string $name,
        ?string $newToken,
        array $capabilities,
        array $metadata,
        bool $automaticDns,
        bool $proxiedDns,
        string $auditSource,
    ): IntegrationToken {
        $provider = $integrationToken->provider;
        $newToken = filled($newToken) ? $newToken : null;
        $metadata = array_filter($metadata, fn ($value) => filled($value));
        if ($provider === 'cloudflare') {
            // Cloudflare has no user settings in metadata; keep system keys such as zones_synced_at.
            $metadata = $integrationToken->metadata ?? [];
            unset($metadata['automatic_dns'], $metadata['proxied_dns']);
            if (! $automaticDns) {
                $metadata['automatic_dns'] = false;
            }
            if ($proxiedDns) {
                $metadata['proxied_dns'] = true;
            }
        }

        $storedMetadata = $integrationToken->metadata ?? [];
        $changedConnectionFields = $this->changedConnectionFields($provider, $storedMetadata, $metadata);
        if ($changedConnectionFields !== [] && $newToken === null) {
            throw ValidationException::withMessages(['token' => $this->reenterSecretMessage($provider)]);
        }
        $capabilitiesChanged = collect($capabilities)->sort()->values()->all()
            !== collect($integrationToken->capabilities)->sort()->values()->all();

        if (($newToken !== null || $capabilitiesChanged || $metadata != $storedMetadata)
            && ! $this->validator->validate($provider, $newToken ?? $integrationToken->token, $capabilities, $metadata)) {
            throw new RuntimeException($this->validator->errorMessage($provider));
        }

        $updates = ['name' => $name, 'capabilities' => $capabilities, 'metadata' => $metadata ?: null];
        if ($newToken !== null) {
            $updates['token'] = $newToken;
        }

        DB::transaction(function () use ($integrationToken, $updates, $provider, $newToken, $capabilitiesChanged): void {
            $integrationToken->update($updates);
            if ($provider === 'cloudflare' && ($newToken !== null || $capabilitiesChanged)) {
                $this->cloudflare->syncZones($integrationToken);
            }
        });

        auditLog("{$auditSource}.integration_token.updated", [
            'team_id' => $integrationToken->team_id,
            'integration_token_uuid' => $integrationToken->uuid,
            'integration_token_name' => $integrationToken->name,
            'provider' => $provider,
            'rotated' => $newToken !== null,
            'connection_changed_fields' => $changedConnectionFields,
        ]);

        if (in_array('base_url', $changedConnectionFields, true)) {
            auditLog("{$auditSource}.integration_token.base_url_changed", [
                'team_id' => $integrationToken->team_id,
                'integration_token_uuid' => $integrationToken->uuid,
                'integration_token_name' => $integrationToken->name,
                'provider' => $provider,
                'previous_base_url' => $this->baseUrlForAudit(data_get($storedMetadata, 'base_url')),
                'base_url' => $this->baseUrlForAudit(data_get($metadata, 'base_url')),
            ], 'warning');
        }

        return $integrationToken;
    }

    /**
     * Metadata keys that decide where or how the stored secret is sent.
     * Changing any of them must require the secret to be entered again.
     *
     * @return array<int, string>
     */
    private function connectionFields(string $provider): array
    {
        return match ($provider) {
            'vault' => ['base_url', 'namespace'],
            'infisical' => ['base_url', 'client_id'],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $storedMetadata
     * @param  array<string, mixed>  $metadata
     * @return array<int, string>
     */
    private function changedConnectionFields(string $provider, array $storedMetadata, array $metadata): array
    {
        return array_values(array_filter(
            $this->connectionFields($provider),
            fn (string $field): bool => (string) data_get($storedMetadata, $field, '') !== (string) data_get($metadata, $field, ''),
        ));
    }

    /**
     * Base URL without user info, query, or fragment, which can carry credentials.
     */
    private function baseUrlForAudit(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '[invalid-url]';
        }

        return $parts['scheme'].'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '');
    }

    private function reenterSecretMessage(string $provider): string
    {
        return match ($provider) {
            'infisical' => 'Enter the client secret again when you change the base URL or the client ID.',
            'vault' => 'Enter the token again when you change the base URL or the namespace.',
            default => 'Enter the token again when you change the connection settings.',
        };
    }
}
