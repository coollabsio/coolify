<?php

namespace App\Models;

use App\Notifications\Channels\SendsEmail;
use App\Notifications\Channels\SendsDiscord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Spatie\SchemalessAttributes\Casts\SchemalessAttributes;

class Team extends Model implements SendsDiscord, SendsEmail
{
    use Notifiable;

    protected $casts = [
        'extra_attributes' => SchemalessAttributes::class,
        'personal_team' => 'boolean',
    ];
    protected $fillable = [
        'id',
        'name',
        'description',
        'personal_team',
        'extra_attributes',
    ];

    public function routeNotificationForDiscord()
    {
        return $this->extra_attributes->get('discord_webhook');
    }
    public function routeNotificationForEmail(string $attribute = 'smtp_recipients')
    {
        $recipients = $this->extra_attributes->get($attribute, '');
        if (is_null($recipients) || $recipients === '') {
            return [];
        }
        return explode(',', $recipients);
    }

    public function scopeWithExtraAttributes(): Builder
    {
        return $this->extra_attributes->modelScope();
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
}
