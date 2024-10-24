<?php

namespace App\Models;

class GitlabApp extends BaseModel
{
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
