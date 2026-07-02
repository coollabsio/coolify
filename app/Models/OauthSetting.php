<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class OauthSetting extends Model
{
    use HasFactory;

    protected $fillable = ['provider', 'client_id', 'client_secret', 'redirect_uri', 'tenant', 'base_url', 'enabled', 'custom_label', 'scopes', 'allow_registration', 'auto_join_root_team', 'require_email_verified', 'use_pkce', 'clock_skew_seconds'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'allow_registration' => 'boolean',
            'auto_join_root_team' => 'boolean',
            'require_email_verified' => 'boolean',
            'use_pkce' => 'boolean',
            'clock_skew_seconds' => 'integer',
        ];
    }

    protected function clientSecret(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => empty($value) ? null : Crypt::decryptString($value),
            set: fn (?string $value) => empty($value) ? null : Crypt::encryptString($value),
        );
    }

    public function couldBeEnabled(): bool
    {
        switch ($this->provider) {
            case 'azure':
                return filled($this->client_id) && filled($this->client_secret) && filled($this->tenant);
            case 'authentik':
            case 'clerk':
            case 'oidc':
                return filled($this->client_id) && filled($this->client_secret) && filled($this->base_url);
            default:
                return filled($this->client_id) && filled($this->client_secret);
        }
    }

    /**
     * @return array<int, string>
     */
    public function scopeList(): array
    {
        $scopes = str($this->scopes ?: 'openid email profile')
            ->replace(',', ' ')
            ->explode(' ')
            ->map(fn (string $scope) => trim($scope))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $scopes === [] ? ['openid', 'email', 'profile'] : $scopes;
    }

    public function loginLabel(): string
    {
        if (filled($this->custom_label)) {
            return $this->custom_label;
        }

        $envLabel = config("services.{$this->provider}.custom_label");
        if (filled($envLabel)) {
            return $envLabel;
        }

        return __("auth.login.{$this->provider}");
    }

    public function isOidc(): bool
    {
        return $this->provider === 'oidc';
    }
}
