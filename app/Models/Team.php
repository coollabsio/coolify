<?php

namespace App\Models;

use App\Notifications\Channels\SendsEmail;
use App\Notifications\Channels\SendsDiscord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\Notifiable;
use Spatie\SchemalessAttributes\Casts\SchemalessAttributes;

class Team extends BaseModel implements SendsDiscord, SendsEmail
{
    use Notifiable;

    protected $casts = [
        'smtp_attributes' => SchemalessAttributes::class,
        'personal_team' => 'boolean',
    ];
    protected $fillable = [
        'id',
        'name',
        'personal_team',
        'smtp_attributes',
    ];

    public function routeNotificationForDiscord()
    {
        return $this->smtp_attributes->get('discord_webhook');
    }

    public function routeNotificationForEmail(string $attribute = 'recipients')
    {
        $recipients = $this->smtp_attributes->get($attribute, '');
        return explode(PHP_EOL, $recipients);
    }

    public function scopeWithExtraAttributes(): Builder
    {
        return $this->smtp_attributes->modelScope();
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
}
