<?php

namespace App\Models;

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
        return GitlabApp::whereTeamId(currentTeam()->id);
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
