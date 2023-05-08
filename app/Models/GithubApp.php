<?php

namespace App\Models;

class GithubApp extends BaseModel
{
    protected $casts = [
        'is_public' => 'boolean',
    ];
    public function applications()
    {
        return $this->morphMany(Application::class, 'source');
    }
    public function privateKey()
    {
        return $this->belongsTo(PrivateKey::class);
    }
    static public function public()
    {
        return GithubApp::where('team_id', session('currentTeam')->id)->where('is_public', true)->get();
    }
    static public function private()
    {
        return GithubApp::where('team_id', session('currentTeam')->id)->where('is_public', false)->get();
    }
}
