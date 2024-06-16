<?php

namespace App\Models;

use App\Notifications\Channels\SendsDiscord;
use App\Notifications\Channels\SendsEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property bool $personal_team
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property bool $smtp_enabled
 * @property string|null $smtp_from_address
 * @property string|null $smtp_from_name
 * @property string|null $smtp_recipients
 * @property string|null $smtp_host
 * @property int|null $smtp_port
 * @property string|null $smtp_encryption
 * @property string|null $smtp_username
 * @property mixed|null $smtp_password
 * @property int|null $smtp_timeout
 * @property bool $smtp_notifications_test
 * @property bool $smtp_notifications_deployments
 * @property bool $smtp_notifications_status_changes
 * @property bool $discord_enabled
 * @property string|null $discord_webhook_url
 * @property bool $discord_notifications_test
 * @property bool $discord_notifications_deployments
 * @property bool $discord_notifications_status_changes
 * @property bool $smtp_notifications_database_backups
 * @property bool $discord_notifications_database_backups
 * @property bool $show_boarding
 * @property bool $resend_enabled
 * @property mixed|null $resend_api_key
 * @property bool $use_instance_email_settings
 * @property bool $telegram_enabled
 * @property string|null $telegram_token
 * @property string|null $telegram_chat_id
 * @property bool $telegram_notifications_test
 * @property bool $telegram_notifications_deployments
 * @property bool $telegram_notifications_status_changes
 * @property bool $telegram_notifications_database_backups
 * @property string|null $telegram_notifications_test_message_thread_id
 * @property string|null $telegram_notifications_deployments_message_thread_id
 * @property string|null $telegram_notifications_status_changes_message_thread_id
 * @property string|null $telegram_notifications_database_backups_message_thread_id
 * @property int|null $custom_server_limit
 * @property bool $telegram_notifications_scheduled_tasks
 * @property bool $smtp_notifications_scheduled_tasks
 * @property bool $discord_notifications_scheduled_tasks
 * @property string|null $telegram_notifications_scheduled_tasks_thread_id
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Application> $applications
 * @property-read int|null $applications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SharedEnvironmentVariable> $environment_variables
 * @property-read int|null $environment_variables_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\TeamInvitation> $invitations
 * @property-read int|null $invitations_count
 * @property-read mixed $limits
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $members
 * @property-read int|null $members_count
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PrivateKey> $privateKeys
 * @property-read int|null $private_keys_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Project> $projects
 * @property-read int|null $projects_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\S3Storage> $s3s
 * @property-read int|null $s3s_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Server> $servers
 * @property-read int|null $servers_count
 * @property-read \App\Models\Subscription|null $subscription
 *
 * @method static \Database\Factories\TeamFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder|Team newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Team newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Team query()
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereCustomServerLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereDiscordEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereDiscordNotificationsDatabaseBackups($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereDiscordNotificationsDeployments($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereDiscordNotificationsScheduledTasks($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereDiscordNotificationsStatusChanges($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereDiscordNotificationsTest($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereDiscordWebhookUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team wherePersonalTeam($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereResendApiKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereResendEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereShowBoarding($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereSmtpEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereSmtpEncryption($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereSmtpFromAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereSmtpFromName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereSmtpHost($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereSmtpNotificationsDatabaseBackups($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereSmtpNotificationsDeployments($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereSmtpNotificationsScheduledTasks($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereSmtpNotificationsStatusChanges($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereSmtpNotificationsTest($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereSmtpPassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereSmtpPort($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereSmtpRecipients($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereSmtpTimeout($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereSmtpUsername($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereTelegramChatId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereTelegramEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereTelegramNotificationsDatabaseBackups($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereTelegramNotificationsDatabaseBackupsMessageThreadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereTelegramNotificationsDeployments($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereTelegramNotificationsDeploymentsMessageThreadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereTelegramNotificationsScheduledTasks($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereTelegramNotificationsScheduledTasksThreadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereTelegramNotificationsStatusChanges($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereTelegramNotificationsStatusChangesMessageThreadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereTelegramNotificationsTest($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereTelegramNotificationsTestMessageThreadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereTelegramToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereUseInstanceEmailSettings($value)
 *
 * @mixin \Eloquent
 */
class Team extends Model implements SendsDiscord, SendsEmail
{
    use HasFactory, Notifiable;

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
