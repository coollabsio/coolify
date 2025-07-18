<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;

class GithubApp extends BaseModel
{
    protected $guarded = [];

    protected $appends = ['type'];

    protected $casts = [
        'is_public' => 'boolean',
        'type' => 'string',
    ];

    protected $hidden = [
        'client_secret',
        'webhook_secret',
    ];

    protected static function booted(): void
    {
        static::deleting(function (GithubApp $github_app) {
            $applications_count = Application::where('source_id', $github_app->id)->count();
            if ($applications_count > 0) {
                throw new \Exception('You cannot delete this GitHub App because it is in use by '.$applications_count.' application(s). Delete them first.');
            }
            $github_app->privateKey()->delete();
        });
    }

    public static function ownedByCurrentTeam()
    {
        return GithubApp::where(function ($query) {
            $query->where('team_id', currentTeam()->id)
                ->orWhere('is_system_wide', true);
        });
    }

    public static function public()
    {
        return GithubApp::where(function ($query) {
            $query->where(function ($q) {
                $q->where('team_id', currentTeam()->id)
                    ->orWhere('is_system_wide', true);
            })->where('is_public', true);
        })->whereNotNull('app_id')->get();
    }

    public static function private()
    {
        return GithubApp::where(function ($query) {
            $query->where(function ($q) {
                $q->where('team_id', currentTeam()->id)
                    ->orWhere('is_system_wide', true);
            })->where('is_public', false);
        })->whereNotNull('app_id')->get();
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
}
