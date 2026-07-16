<?php

namespace App\Models;

class CloudProviderToken extends BaseModel
{
    protected $fillable = [
        'team_id',
        'provider',
        'token',
        'name',
    ];

    protected $casts = [
        'token' => 'encrypted',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function servers()
    {
        return $this->hasMany(Server::class);
    }

    public function hasServers(): bool
    {
        return $this->servers()->exists();
    }

    public static function ownedByCurrentTeam(array $select = ['*'])
    {
        $selectArray = collect($select)->concat(['id']);

        return self::whereTeamId(currentTeam()->id)->select($selectArray->all());
    }

    public function scopeForProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    public function isHetzner(): bool
    {
        return $this->provider === 'hetzner';
    }

    public function isOpenstack(): bool
    {
        return $this->provider === 'openstack';
    }

    /**
     * For OpenStack the encrypted `token` column stores a JSON blob of
     * credentials rather than a single bearer token.
     *
     * @return array{auth_url?: string, application_credential_id?: string, application_credential_secret?: string, region?: string|null}
     */
    public function credentials(): array
    {
        $decoded = json_decode((string) $this->token, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Non-sensitive view of the stored credentials, safe to render in the UI.
     * Only applicable to OpenStack tokens.
     *
     * @return array{auth_url: ?string, application_credential_id: ?string, region: ?string}
     */
    public function maskedCredentials(): array
    {
        $credentials = $this->credentials();

        return [
            'auth_url' => $credentials['auth_url'] ?? null,
            'application_credential_id' => $credentials['application_credential_id'] ?? null,
            'region' => $credentials['region'] ?? null,
        ];
    }
}
