<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Crypt;

class GitlabApp extends BaseModel
{
    protected $fillable = [
        'name',
        'organization',
        'api_url',
        'html_url',
        'custom_port',
        'custom_user',
        'is_system_wide',
        'is_public',
        'app_id',
        'app_secret',
        'oauth_id',
        'client_id',
        'client_secret',
        'access_token',
        'refresh_token',
        'expires_at',
        'redirect_uri',
        'group_name',
        'public_key',
        'webhook_token',
        'deploy_key_id',
        'private_key_id',
        'team_id',
    ];

    protected $hidden = [
        'webhook_token',
        'app_secret',
        'client_secret',
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'client_secret' => 'encrypted',
            'is_system_wide' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    /**
     * Encrypt webhook tokens at rest. Supports legacy plaintext values until they are re-saved.
     * Not a standard encrypted cast: webhooks look up by token value (see findByWebhookToken).
     */
    protected function webhookToken(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): ?string {
                if ($value === null || $value === '') {
                    return $value;
                }

                try {
                    return Crypt::decryptString($value);
                } catch (DecryptException) {
                    // Legacy rows stored the token in plaintext.
                    return $value;
                }
            },
            set: function (?string $value): ?string {
                if ($value === null || $value === '') {
                    return $value;
                }

                return Crypt::encryptString($value);
            },
        );
    }

    public static function findByWebhookToken(string $token): ?self
    {
        if ($token === '') {
            return null;
        }

        // Encrypted values cannot be matched with a SQL equality; sources are few per instance.
        return static::query()->get()->first(
            fn (self $app): bool => filled($app->webhook_token) && hash_equals((string) $app->webhook_token, $token)
        );
    }

    protected static function booted(): void
    {
        static::deleting(function (GitlabApp $gitlabApp) {
            if ($gitlabApp->applications()->count() > 0) {
                throw new \RuntimeException('This source is being used by an application. Please delete all applications first.');
            }

        });
    }

    public static function ownedByCurrentTeam()
    {
        return GitlabApp::where(function ($query) {
            $query->where('team_id', currentTeam()->id)
                ->orWhere('is_system_wide', true);
        });
    }

    public static function public()
    {
        return GitlabApp::where(function ($query) {
            $query->where('team_id', currentTeam()->id)->orWhere('is_system_wide', true);
        })->where('is_public', true);
    }

    public static function private()
    {
        return GitlabApp::where(function ($query) {
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

        if (! str_contains($apiUrl, '/api/v4')) {
            $apiUrl .= '/api/v4';
        }

        return $apiUrl;
    }
}
