<?php

namespace App\Models;

use App\Notifications\Channels\SendsEmail;
use App\Notifications\Channels\SendsDiscord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Spatie\SchemalessAttributes\Casts\SchemalessAttributes;
use Spatie\SchemalessAttributes\SchemalessAttributesTrait;

class Team extends Model implements SendsDiscord, SendsEmail
{
    use Notifiable, SchemalessAttributesTrait;

    protected $schemalessAttributes = [
        'smtp',
        'discord',
        'smtp_notifications',
        'discord_notifications',
    ];
    protected $casts = [
        'smtp' => SchemalessAttributes::class,
        'discord' => SchemalessAttributes::class,
        'smtp_notifications' => SchemalessAttributes::class,
        'discord_notifications' => SchemalessAttributes::class,
        'personal_team' => 'boolean',
    ];
    public function scopeWithSmtp(): Builder
    {
        return $this->smtp->modelScope();
    }
    public function scopeWithDiscord(): Builder
    {
        return $this->discord->modelScope();
    }
    public function scopeWithSmtpNotifications(): Builder
    {
        return $this->smtp_notifications->modelScope();
    }
    public function scopeWithDiscordNotifications(): Builder
    {
        return $this->discord_notifications->modelScope();
    }
    protected $fillable = [
        'id',
        'name',
        'description',
        'personal_team',
        'smtp',
        'discord'
    ];

    public function routeNotificationForDiscord()
    {
        return $this->discord->get('webhook_url');
    }
    public function routeNotificationForEmail(string $attribute = 'recipients')
    {
        $recipients = $this->smtp->get($attribute, '');
        if (is_null($recipients) || $recipients === '') {
            return [];
        }
        return explode(',', $recipients);
    }



    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    public function servers()
    {
        return $this->hasMany(Server::class);
    }

    public function applications()
    {
        return $this->hasManyThrough(Application::class, Project::class);
    }

    public function privateKeys()
    {
        return $this->hasMany(PrivateKey::class);
    }
    public function members()
    {
        return $this->belongsToMany(User::class, 'team_user', 'team_id', 'user_id')->withPivot('role');
    }
    public function invitations()
    {
        return $this->hasMany(TeamInvitation::class);
    }
    public function sources()
    {
        $sources = collect([]);
        $github_apps = $this->hasMany(GithubApp::class)->whereisPublic(false)->get();
        $gitlab_apps = $this->hasMany(GitlabApp::class)->whereisPublic(false)->get();
        // $bitbucket_apps = $this->hasMany(BitbucketApp::class)->get();
        $sources = $sources->merge($github_apps)->merge($gitlab_apps);
        return $sources;
    }
    public function isEmpty()
    {
        if ($this->projects()->count() === 0 && $this->servers()->count() === 0 && $this->privateKeys()->count() === 0 && $this->sources()->count() === 0) {
            return true;
        }
        return false;
    }
}
