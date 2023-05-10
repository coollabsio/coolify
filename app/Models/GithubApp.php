<?php

namespace App\Models;

class GithubApp extends BaseModel
{
    protected $fillable = ['name', 'uuid', 'organization', 'api_url', 'html_url', 'custom_user', 'custom_port', 'team_id'];
    protected $casts = [
        'is_public' => 'boolean',
    ];
    protected static function booted(): void
    {
        static::deleting(function (GithubApp $github_app) {
            $applications_count = Application::where('source_id', $github_app->id)->count();
            if ($applications_count > 0) {
                throw new \Exception('You cannot delete this GitHub App because it is in use by ' . $applications_count . ' application(s). Delete them first.');
            }
        });
    }
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
