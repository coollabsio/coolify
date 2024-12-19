<?php

namespace App\Models;

use App\Events\ServerReachabilityChanged;
use App\Notifications\Channels\SendsDiscord;
use App\Notifications\Channels\SendsEmail;
use App\Notifications\Channels\SendsPushover;
use App\Notifications\Channels\SendsSlack;
use App\Traits\HasNotificationSettings;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'Team model',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer', 'description' => 'The unique identifier of the team.'],
        'name' => ['type' => 'string', 'description' => 'The name of the team.'],
        'description' => ['type' => 'string', 'description' => 'The description of the team.'],
        'personal_team' => ['type' => 'boolean', 'description' => 'Whether the team is personal or not.'],
        'created_at' => ['type' => 'string', 'description' => 'The date and time the team was created.'],
        'updated_at' => ['type' => 'string', 'description' => 'The date and time the team was last updated.'],
        'show_boarding' => ['type' => 'boolean', 'description' => 'Whether to show the boarding screen or not.'],
        'custom_server_limit' => ['type' => 'string', 'description' => 'The custom server limit.'],
        'members' => new OA\Property(
            property: 'members',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/User'),
            description: 'The members of the team.'
        ),
    ]
)]

class Team extends Model implements SendsDiscord, SendsEmail, SendsPushover, SendsSlack
{
    use HasNotificationSettings, Notifiable;

    protected $guarded = [];

    protected $casts = [
        'personal_team' => 'boolean',
    ];

    protected static function booted()
    {
        static::created(function ($team) {
            $team->emailNotificationSettings()->create();
            $team->discordNotificationSettings()->create();
            $team->slackNotificationSettings()->create();
            $team->telegramNotificationSettings()->create();
            $team->pushoverNotificationSettings()->create();
        });

        static::saving(function ($team) {
            if (auth()->user()?->isMember()) {
                throw new \Exception('You are not allowed to update this team.');
            }
        });

        static::deleting(function ($team) {
            $keys = $team->privateKeys;
            foreach ($keys as $key) {
                $key->delete();
            }
            $sources = $team->sources();
            foreach ($sources as $source) {
                $source->delete();
            }
            $tags = Tag::whereTeamId($team->id)->get();
            foreach ($tags as $tag) {
                $tag->delete();
            }
            $shared_variables = $team->environment_variables();
            foreach ($shared_variables as $shared_variable) {
                $shared_variable->delete();
            }
            $s3s = $team->s3s;
            foreach ($s3s as $s3) {
                $s3->delete();
            }
        });
    }

    public static function serverLimitReached()
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

    public static function serverLimit()
    {
        if (currentTeam()->id === 0 && isDev()) {
            return 9999999;
        }
        $team = Team::find(currentTeam()->id);
        if (! $team) {
            return 0;
        }

        return data_get($team, 'limits', 0);
    }

    public function limits(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (config('constants.coolify.self_hosted') || $this->id === 0) {
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

                return $serverLimit ?? 2;
            }
        );
    }

    public function routeNotificationForDiscord()
    {
        return data_get($this, 'discord_webhook_url', null);
    }

    public function routeNotificationForTelegram()
    {
        return [
            'token' => data_get($this, 'telegram_token', null),
            'chat_id' => data_get($this, 'telegram_chat_id', null),
        ];
    }

    public function routeNotificationForSlack()
    {
        return data_get($this, 'slack_webhook_url', null);
    }

    public function routeNotificationForPushover()
    {
        return [
            'user' => data_get($this, 'pushover_user_key', null),
            'token' => data_get($this, 'pushover_api_token', null),
        ];
    }

    public function getRecipients($notification)
    {
        $recipients = data_get($notification, 'emails', null);
        if (is_null($recipients)) {
            return $this->members()->pluck('email')->toArray();
        }

        return explode(',', $recipients);
    }

    public function isAnyNotificationEnabled()
    {
        if (isCloud()) {
            return true;
        }

        return $this->getNotificationSettings('email')?->isEnabled() ||
            $this->getNotificationSettings('discord')?->isEnabled() ||
            $this->getNotificationSettings('slack')?->isEnabled() ||
            $this->getNotificationSettings('telegram')?->isEnabled() ||
            $this->getNotificationSettings('pushover')?->isEnabled();
    }

    public function subscriptionEnded()
    {
        $this->subscription->update([
            'stripe_subscription_id' => null,
            'stripe_plan_id' => null,
            'stripe_cancel_at_period_end' => false,
            'stripe_invoice_paid' => false,
            'stripe_trial_already_ended' => false,
        ]);
        foreach ($this->servers as $server) {
            $server->settings()->update([
                'is_usable' => false,
                'is_reachable' => false,
            ]);
            ServerReachabilityChanged::dispatch($server);
        }
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

        return $sources->merge($github_apps)->merge($gitlab_apps);
    }

    public function s3s()
    {
        return $this->hasMany(S3Storage::class)->where('is_usable', true);
    }

    public function emailNotificationSettings()
    {
        return $this->hasOne(EmailNotificationSettings::class);
    }

    public function discordNotificationSettings()
    {
        return $this->hasOne(DiscordNotificationSettings::class);
    }

    public function telegramNotificationSettings()
    {
        return $this->hasOne(TelegramNotificationSettings::class);
    }

    public function slackNotificationSettings()
    {
        return $this->hasOne(SlackNotificationSettings::class);
    }

    public function pushoverNotificationSettings()
    {
        return $this->hasOne(PushoverNotificationSettings::class);
    }
}
