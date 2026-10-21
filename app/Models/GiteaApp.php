<?php

namespace App\Models;

use App\Traits\Auditable;

class GiteaApp extends BaseModel
{
    use Auditable;

    protected $fillable = [
        'name',
        'organization',
        'api_url',
        'html_url',
        'custom_port',
        'custom_user',
        'is_system_wide',
        'is_public',
        'client_id',
        'client_secret',
        'access_token',
        'refresh_token',
        'expires_at',
        'redirect_uri',
        'webhook_secret',
        'private_key_id',
        'team_id',
    ];

    protected $hidden = [
        'client_secret',
        'access_token',
        'refresh_token',
        'webhook_secret',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'client_secret' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'is_system_wide' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    /**
     * Find the source whose webhook secret signed the payload.
     * Encrypted secrets cannot be matched with SQL; sources are few per instance.
     */
    public static function findBySignature(string $payload, string $signature): ?self
    {
        if ($signature === '') {
            return null;
        }

        return static::query()->whereNotNull('webhook_secret')->get()->first(
            fn (self $app): bool => filled($app->webhook_secret)
                && hash_equals(hash_hmac('sha256', $payload, (string) $app->webhook_secret), $signature)
        );
    }

    protected static function booted(): void
    {
        static::deleting(function (GiteaApp $giteaApp) {
            if ($giteaApp->applications()->count() > 0) {
                throw new \RuntimeException('This source is being used by an application. Please delete all applications first.');
            }
        });
    }

    public static function ownedByCurrentTeam()
    {
        return GiteaApp::where(function ($query) {
            $query->where('team_id', currentTeam()->id)
                ->orWhere('is_system_wide', true);
        });
    }

    public static function private()
    {
        return GiteaApp::where(function ($query) {
            $query->where('team_id', currentTeam()->id)->orWhere('is_system_wide', true);
        })->where('is_public', false)->whereNotNull('access_token');
    }

    public function applications()
    {
        return $this->morphMany(Application::class, 'source');
    }

    public function privateKey()
    {
        return $this->belongsTo(PrivateKey::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function isConnected(): bool
    {
        return ! empty($this->access_token) && ! empty($this->refresh_token);
    }

    public function apiUrlBase(): string
    {
        $apiUrl = rtrim($this->api_url, '/');

        if (! str_contains($apiUrl, '/api/v1')) {
            $apiUrl .= '/api/v1';
        }

        return $apiUrl;
    }
}
