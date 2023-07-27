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
    use Notifiable;

    protected $guarded = [];
    protected $casts = [
        'personal_team' => 'boolean',
    ];

    public function routeNotificationForDiscord()
    {
        return data_get($this, 'discord_webhook_url', null);
    }
    public function routeNotificationForEmail(string $attribute = 'recipients')
    {
        $recipients = data_get($this, 'smtp_recipients', '');
        if (is_null($recipients) || $recipients === '') {
            return [];
        }
        return explode(',', $recipients);
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class);
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