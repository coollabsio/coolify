<?php

namespace App\Models;

use App\Services\DopplerService;
use App\Services\InfisicalService;
use App\Services\VaultService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Links a resource (currently Application) to a remote secret manager source.
 * Holds only the source coordinates — secret values are never persisted.
 */
class SecretManagerLink extends BaseModel
{
    protected $fillable = [
        'resourceable_type',
        'resourceable_id',
        'integration_token_id',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function resourceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function integrationToken(): BelongsTo
    {
        return $this->belongsTo(IntegrationToken::class);
    }

    /**
     * Fetch the secrets from the remote manager. Values live only in memory.
     *
     * @return array<string, string>
     */
    public function fetchSecrets(): array
    {
        $token = $this->integrationToken;
        $settings = $this->settings ?? [];
        $metadata = $token->metadata ?? [];

        return match ($token->provider) {
            'doppler' => (new DopplerService($token->token))->fetchSecrets(
                data_get($settings, 'project'),
                data_get($settings, 'config'),
            ),
            'infisical' => (new InfisicalService(
                data_get($metadata, 'base_url', 'https://app.infisical.com'),
                (string) data_get($metadata, 'client_id'),
                $token->token,
            ))->fetchSecrets(
                (string) data_get($settings, 'project_id'),
                (string) data_get($settings, 'environment'),
                (string) data_get($settings, 'secret_path', '/'),
            ),
            'vault' => (new VaultService(
                (string) data_get($metadata, 'base_url'),
                $token->token,
                data_get($metadata, 'namespace'),
            ))->fetchSecrets(
                (string) data_get($settings, 'mount', 'secret'),
                (string) data_get($settings, 'path'),
            ),
            default => throw new \RuntimeException("Unsupported secret manager provider [{$token->provider}]."),
        };
    }

    /**
     * Create one {{vault.KEY}} reference variable per remote key that has no
     * variable with that key yet. Only key names touch the database.
     *
     * @return list<string> The keys that were imported
     */
    public function importMissingReferences(): array
    {
        $keys = array_keys($this->fetchSecrets());
        sort($keys);

        $existing = $this->resourceable->environment_variables()->pluck('key')->flip();
        $imported = [];

        foreach ($keys as $key) {
            if (isset($existing[$key])) {
                continue;
            }

            $this->resourceable->environment_variables()->create([
                'key' => $key,
                'value' => '{{vault.'.$key.'}}',
            ]);
            $imported[] = $key;
        }

        return $imported;
    }

    /** Short human-readable description of the remote source for the UI. */
    public function sourceSummary(): string
    {
        $settings = $this->settings ?? [];

        return match ($this->integrationToken->provider) {
            'doppler' => trim(implode('/', array_filter([
                data_get($settings, 'project'),
                data_get($settings, 'config'),
            ])), '/') ?: 'token scope',
            'infisical' => data_get($settings, 'project_id').'/'.data_get($settings, 'environment').data_get($settings, 'secret_path', '/'),
            'vault' => data_get($settings, 'mount', 'secret').'/'.data_get($settings, 'path'),
            default => '',
        };
    }
}
