<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationToken extends BaseModel
{
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

    public function isSecretManager(): bool
    {
        return in_array($this->provider, self::SECRET_MANAGER_PROVIDERS, true);
    }

    public function providerName(): string
    {
        return self::PROVIDER_NAMES[$this->provider] ?? ucfirst($this->provider);
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
