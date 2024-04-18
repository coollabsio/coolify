<?php

namespace App\Models;

use App\Notifications\Channels\SendsDiscord;
use App\Notifications\Channels\SendsEmail;
use App\Notifications\Channels\SendsPushover;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class Team extends Model implements SendsDiscord, SendsEmail, SendsPushover
{
    use Notifiable;

    protected $guarded = [];
    protected $casts = [
        'personal_team' => 'boolean',
        'smtp_password' => 'encrypted',
        'resend_api_key' => 'encrypted',
    ];

    protected static function booted()
    {
        static::saving(function ($team) {
            if (auth()->user()?->isMember()) {
                throw new \Exception('You are not allowed to update this team.');
            }
        });
    }

    public function routeNotificationForDiscord()
    {
        return data_get($this, 'discord_webhook_url', null);
    }

    public function routeNotificationForTelegram()
    {
        return [
            "token" => data_get($this, 'telegram_token', null),
            "user_key" => data_get($this, 'telegram_user_key', null),
        ];
    }

    public function routeNotificationForPushover()
    {
        return [
            "token" => data_get($this, 'pushover_token', null),
            "user" => data_get($this, 'pushover_user', null),
        ];
    }

    public function getRecepients($notification)
    {
        $recipients = data_get($notification, 'emails', null);
        if (is_null($recipients)) {
            $recipients = $this->members()->pluck('email')->toArray();
            return $recipients;
        }
        return explode(',', $recipients);
    }
    static public function serverLimitReached()
    {
        $serverLimit = Team::serverLimit();
        $team = currentTeam();
        $servers = $team->servers->count();
        return $servers >= $serverLimit;
    }
    public function serverOverflow()
    {
        if ($this->serverLimit() < $this->servers->count()) {
            return true;
        }
        return false;
    }
    static public function serverLimit()
    {
        if (currentTeam()->id === 0 && isDev()) {
            return 9999999;
        }
        return Team::find(currentTeam()->id)->limits['serverLimit'];
    }
    public function limits(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (config('coolify.self_hosted') || $this->id === 0) {
                    $subscription = 'self-hosted';
                } else {
                    $subscription = data_get($this, 'subscription');
                    if (is_null($subscription)) {
                        $subscription = 'zero';
                    } else {
                        $subscription = $subscription->type();
                    }
                }
                if ($this->custom_server_limit) {
                    $serverLimit = $this->custom_server_limit;
                } else {
                    $serverLimit = config('constants.limits.server')[strtolower($subscription)];
                }
                $sharedEmailEnabled = config('constants.limits.email')[strtolower($subscription)];
                return ['serverLimit' => $serverLimit, 'sharedEmailEnabled' => $sharedEmailEnabled];
            }

        );
    }
    public function environment_variables()
    {
        return $this->hasMany(SharedEnvironmentVariable::class)->whereNull('project_id')->whereNull('environment_id');
    }
    public function members()
    {
        return $this->belongsToMany(User::class, 'team_user', 'team_id', 'user_id')->withPivot('role');
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class);
    }

    public function applications()
    {
        return $this->hasManyThrough(Application::class, Project::class);
    }

    public function invitations()
    {
        return $this->hasMany(TeamInvitation::class);
    }

    public function isEmpty()
    {
        if ($this->projects()->count() === 0 && $this->servers()->count() === 0 && $this->privateKeys()->count() === 0 && $this->sources()->count() === 0) {
            return true;
        }
        return false;
    }

    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    public function servers()
    {
        return $this->hasMany(Server::class);
    }

    public function privateKeys()
    {
        return $this->hasMany(PrivateKey::class);
    }

    public function sources()
    {
        $sources = collect([]);
        $github_apps = $this->hasMany(GithubApp::class)->whereisPublic(false)->get();
        $gitlab_apps = $this->hasMany(GitlabApp::class)->whereisPublic(false)->get();
        $sources = $sources->merge($github_apps)->merge($gitlab_apps);
        return $sources;
    }

    public function s3s()
    {
        return $this->hasMany(S3Storage::class)->where('is_usable', true);
    }
    public function trialEnded()
    {
        foreach ($this->servers as $server) {
            $server->settings()->update([
                'is_usable' => false,
                'is_reachable' => false,
            ]);
        }
    }
    public function trialEndedButSubscribed()
    {
        foreach ($this->servers as $server) {
            $server->settings()->update([
                'is_usable' => true,
                'is_reachable' => true,
            ]);
        }
    }
    public function isAnyNotificationEnabled()
    {
        if (isCloud()) {
            return true;
        }

        if ($this->smtp_enabled || $this->resend_enabled || $this->discord_enabled || $this->telegram_enabled || $this->pushover_enabled || $this->use_instance_email_settings) {
            return true;
        }
        return false;
    }
}
