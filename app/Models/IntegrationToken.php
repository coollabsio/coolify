<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationToken extends BaseModel
{
    use HasFactory;

    public const SECRET_MANAGER_PROVIDERS = ['doppler', 'infisical', 'vault'];

    public const PROVIDER_NAMES = [
        'cloudflare' => 'Cloudflare',
        'doppler' => 'Doppler',
        'infisical' => 'Infisical',
        'vault' => 'HashiCorp Vault',
    ];

    protected $fillable = [
        'team_id',
        'provider',
        'name',
        'token',
        'capabilities',
        'metadata',
    ];

    protected $hidden = [
        'token',
    ];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'capabilities' => 'array',
            'metadata' => 'array',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function secretManagerLinks(): HasMany
    {
        return $this->hasMany(SecretManagerLink::class);
    }

    public function dnsZones(): HasMany
    {
        return $this->hasMany(DnsProviderZone::class);
    }

    public function managedDnsRecords(): HasMany
    {
        return $this->hasMany(ManagedDnsRecord::class);
    }

    public function isSecretManager(): bool
    {
        return in_array($this->provider, self::SECRET_MANAGER_PROVIDERS, true);
    }

    public function providerName(): string
    {
        return self::PROVIDER_NAMES[$this->provider] ?? ucfirst($this->provider);
    }

    /**
     * Capabilities a token of the provider can have. Cloudflare Tunnel setup also writes DNS records, so `tunnel` needs `dns`.
     *
     * @return array<int, string>
     */
    public static function allowedCapabilities(string $provider): array
    {
        return $provider === 'cloudflare' ? ['dns', 'tunnel'] : ['secrets'];
    }

    /**
     * Cloudflare dashboard link that pre-fills a user API token with the permissions the capabilities need.
     *
     * @param  array<int, string>  $capabilities
     */
    public static function cloudflareTokenTemplateUrl(array $capabilities): string
    {
        $permissions = [
            ['key' => 'dns', 'type' => 'edit'],
            ['key' => 'zone', 'type' => 'read'],
        ];
        if (in_array('tunnel', $capabilities, true)) {
            // `argotunnel` is Cloudflare's internal key for the "Cloudflare Tunnel" permission group.
            $permissions[] = ['key' => 'argotunnel', 'type' => 'edit'];
        }

        return 'https://dash.cloudflare.com/profile/api-tokens?'.http_build_query([
            'permissionGroupKeys' => json_encode($permissions),
            'accountId' => '*',
            'zoneId' => 'all',
            'name' => in_array('tunnel', $capabilities, true) ? 'Coolify DNS and Tunnel Management' : 'Coolify DNS Management',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities ?? [], true);
    }

    public function automaticDnsEnabled(): bool
    {
        return $this->provider === 'cloudflare' && data_get($this->metadata, 'automatic_dns', true) !== false;
    }

    /** New Cloudflare records are created proxied (orange cloud) when enabled, so the server IP is not exposed. */
    public function proxiedDnsEnabled(): bool
    {
        return $this->provider === 'cloudflare' && data_get($this->metadata, 'proxied_dns') === true;
    }

    /**
     * Optional request timeout in seconds for self-hosted secret managers.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function requestTimeout(array $metadata): ?int
    {
        return filled($metadata['timeout'] ?? null) ? (int) $metadata['timeout'] : null;
    }

    public function dopplerTokenType(): ?string
    {
        if ($this->provider !== 'doppler') {
            return null;
        }

        return match (true) {
            str_starts_with($this->token, 'dp.st.') => 'service',
            str_starts_with($this->token, 'dp.sa.') => 'service_account',
            default => null,
        };
    }

    public static function ownedByCurrentTeam()
    {
        return self::query()->where('team_id', currentTeam()->id);
    }
}
