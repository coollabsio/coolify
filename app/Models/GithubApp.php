<?php

namespace App\Models;

use Exception;
use Illuminate\Database\Eloquent\Casts\Attribute;

class GithubApp extends BaseModel
{
    protected $guarded = [];

    protected $appends = ['type'];

    protected $hidden = [
        'client_secret',
        'webhook_secret',
    ];

    protected static function booted(): void
    {
        static::deleting(function (GithubApp $githubApp) {
            $applications_count = Application::query()->where('source_id', $githubApp->id)->count();
            if ($applications_count > 0) {
                throw new Exception('You cannot delete this GitHub App because it is in use by '.$applications_count.' application(s). Delete them first.');
            }
            $githubApp->privateKey()->delete();
        });
    }

    public static function ownedByCurrentTeam()
    {
        return GithubApp::whereTeamId(currentTeam()->id);
    }

    public static function public()
    {
        return GithubApp::whereTeamId(currentTeam()->id)->whereisPublic(true)->whereNotNull('app_id')->get();
    }

    public static function private()
    {
        return GithubApp::whereTeamId(currentTeam()->id)->whereisPublic(false)->whereNotNull('app_id')->get();
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
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
                if ($this->getMorphClass() === \App\Models\GithubApp::class) {
                    return 'github';
                }
            },
        );
    }

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'type' => 'string',
        ];
    }
}
