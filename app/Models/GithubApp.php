<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;

class GithubApp extends BaseModel
{
    protected $fillable = ['name', 'uuid', 'organization', 'api_url', 'html_url', 'custom_user', 'custom_port', 'team_id', 'client_secret', 'webhook_secret'];
    protected $appends = ['type'];
    protected $casts = [
        'is_public' => 'boolean',
        'type' => 'string'
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
    public function type(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->getMorphClass() === 'App\Models\GithubApp') {
                    return 'github';
                }
            },
        );
    }
    static public function public()
    {
        return GithubApp::whereTeamId(session('currentTeam')->id)->whereisPublic(true)->whereNotNull('app_id')->get();
    }
    static public function private()
    {
        return GithubApp::whereTeamId(session('currentTeam')->id)->whereisPublic(false)->whereNotNull('app_id')->get();
    }
}
