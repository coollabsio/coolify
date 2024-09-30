<?php

namespace App\Models;

use App\Notifications\Channels\SendsDiscord;
use App\Notifications\Channels\SendsEmail;
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
        'smtp_enabled' => ['type' => 'boolean', 'description' => 'Whether SMTP is enabled or not.'],
        'smtp_from_address' => ['type' => 'string', 'description' => 'The email address to send emails from.'],
        'smtp_from_name' => ['type' => 'string', 'description' => 'The name to send emails from.'],
        'smtp_recipients' => ['type' => 'string', 'description' => 'The email addresses to send emails to.'],
        'smtp_host' => ['type' => 'string', 'description' => 'The SMTP host.'],
        'smtp_port' => ['type' => 'string', 'description' => 'The SMTP port.'],
        'smtp_encryption' => ['type' => 'string', 'description' => 'The SMTP encryption.'],
        'smtp_username' => ['type' => 'string', 'description' => 'The SMTP username.'],
        'smtp_password' => ['type' => 'string', 'description' => 'The SMTP password.'],
        'smtp_timeout' => ['type' => 'string', 'description' => 'The SMTP timeout.'],
        'smtp_notifications_test' => ['type' => 'boolean', 'description' => 'Whether to send test notifications via SMTP.'],
        'smtp_notifications_deployments' => ['type' => 'boolean', 'description' => 'Whether to send deployment notifications via SMTP.'],
        'smtp_notifications_status_changes' => ['type' => 'boolean', 'description' => 'Whether to send status change notifications via SMTP.'],
        'smtp_notifications_scheduled_tasks' => ['type' => 'boolean', 'description' => 'Whether to send scheduled task notifications via SMTP.'],
        'smtp_notifications_database_backups' => ['type' => 'boolean', 'description' => 'Whether to send database backup notifications via SMTP.'],
        'discord_enabled' => ['type' => 'boolean', 'description' => 'Whether Discord is enabled or not.'],
        'discord_webhook_url' => ['type' => 'string', 'description' => 'The Discord webhook URL.'],
        'discord_notifications_test' => ['type' => 'boolean', 'description' => 'Whether to send test notifications via Discord.'],
        'discord_notifications_deployments' => ['type' => 'boolean', 'description' => 'Whether to send deployment notifications via Discord.'],
        'discord_notifications_status_changes' => ['type' => 'boolean', 'description' => 'Whether to send status change notifications via Discord.'],
        'discord_notifications_database_backups' => ['type' => 'boolean', 'description' => 'Whether to send database backup notifications via Discord.'],
        'discord_notifications_scheduled_tasks' => ['type' => 'boolean', 'description' => 'Whether to send scheduled task notifications via Discord.'],
        'show_boarding' => ['type' => 'boolean', 'description' => 'Whether to show the boarding screen or not.'],
        'resend_enabled' => ['type' => 'boolean', 'description' => 'Whether to enable resending or not.'],
        'resend_api_key' => ['type' => 'string', 'description' => 'The resending API key.'],
        'use_instance_email_settings' => ['type' => 'boolean', 'description' => 'Whether to use instance email settings or not.'],
        'telegram_enabled' => ['type' => 'boolean', 'description' => 'Whether Telegram is enabled or not.'],
        'telegram_token' => ['type' => 'string', 'description' => 'The Telegram token.'],
        'telegram_chat_id' => ['type' => 'string', 'description' => 'The Telegram chat ID.'],
        'telegram_notifications_test' => ['type' => 'boolean', 'description' => 'Whether to send test notifications via Telegram.'],
        'telegram_notifications_deployments' => ['type' => 'boolean', 'description' => 'Whether to send deployment notifications via Telegram.'],
        'telegram_notifications_status_changes' => ['type' => 'boolean', 'description' => 'Whether to send status change notifications via Telegram.'],
        'telegram_notifications_database_backups' => ['type' => 'boolean', 'description' => 'Whether to send database backup notifications via Telegram.'],
        'telegram_notifications_test_message_thread_id' => ['type' => 'string', 'description' => 'The Telegram test message thread ID.'],
        'telegram_notifications_deployments_message_thread_id' => ['type' => 'string', 'description' => 'The Telegram deployment message thread ID.'],
        'telegram_notifications_status_changes_message_thread_id' => ['type' => 'string', 'description' => 'The Telegram status change message thread ID.'],
        'telegram_notifications_database_backups_message_thread_id' => ['type' => 'string', 'description' => 'The Telegram database backup message thread ID.'],
        'custom_server_limit' => ['type' => 'string', 'description' => 'The custom server limit.'],
        'telegram_notifications_scheduled_tasks' => ['type' => 'boolean', 'description' => 'Whether to send scheduled task notifications via Telegram.'],
        'telegram_notifications_scheduled_tasks_thread_id' => ['type' => 'string', 'description' => 'The Telegram scheduled task message thread ID.'],
        'members' => new OA\Property(
            property: 'members',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/User'),
            description: 'The members of the team.'
        ),
    ]
)]
class Team extends Model implements SendsDiscord, SendsEmail
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

        static::deleting(function ($team) {
            $keys = $team->privateKeys;
            foreach ($keys as $key) {
                ray('Deleting key: '.$key->name);
                $key->delete();
            }
            $sources = $team->sources();
            foreach ($sources as $source) {
                ray('Deleting source: '.$source->name);
                $source->delete();
            }
            $tags = Tag::whereTeamId($team->id)->get();
            foreach ($tags as $tag) {
                ray('Deleting tag: '.$tag->name);
                $tag->delete();
            }
            $shared_variables = $team->environment_variables();
            foreach ($shared_variables as $shared_variable) {
                ray('Deleting team shared variable: '.$shared_variable->name);
                $shared_variable->delete();
            }
            $s3s = $team->s3s;
            foreach ($s3s as $s3) {
                ray('Deleting s3: '.$s3->name);
                $s3->delete();
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
            'token' => data_get($this, 'telegram_token', null),
            'chat_id' => data_get($this, 'telegram_chat_id', null),
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
        if ($this->smtp_enabled || $this->resend_enabled || $this->discord_enabled || $this->telegram_enabled || $this->use_instance_email_settings) {
            return true;
        }

        return false;
    }
}
