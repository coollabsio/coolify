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
        'group_name',
        'public_key',
        'webhook_token',
        'deploy_key_id',
    ];

    protected $hidden = [
        'webhook_token',
        'app_secret',
    ];

    public static function ownedByCurrentTeam()
    {
        return GitlabApp::whereTeamId(currentTeam()->id);
    }

    public function applications()
    {
        return $this->morphMany(Application::class, 'source');
    }

    public function privateKey()
    {
        return $this->belongsTo(PrivateKey::class);
    }
}
