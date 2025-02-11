<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Carbon;

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

    public function getCoolifyConfig($baseDirectory, $git_repository, $git_branch)
    {
        try {
            if ($baseDirectory !== '/') {
                $baseDirectory = $baseDirectory.'/';
            }
            $githubApiPath = "/repos/{$git_repository}/contents{$baseDirectory}coolify.json?ref={$git_branch}";
            ['rate_limit_reset' => $rate_limit_reset, 'data' => $coolifyJson] = githubApi(source: $this, endpoint: $githubApiPath);
            $coolify_config = base64_decode(data_get($coolifyJson, 'content'));
            $rate_limit_reset = Carbon::parse((int) $rate_limit_reset)->format('Y-M-d H:i:s');

            return [
                'coolify_config' => $coolify_config,
                'rate_limit_reset' => $rate_limit_reset
            ];
        } catch (\Throwable $e) {
            return [
                'coolify_config' => null,
                'rate_limit_reset' => null
            ];
        }
    }
}
