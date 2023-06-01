<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Spatie\SchemalessAttributes\Casts\SchemalessAttributes;

class Server extends BaseModel
{
    protected static function booted()
    {
        static::created(function ($server) {
            ServerSetting::create([
                'server_id' => $server->id,
            ]);
        });
    }
    protected $fillable = [
        'name',
        'ip',
        'user',
        'port',
        'team_id',
        'private_key_id',
        'extra_attributes',
        'smtp_attributes',
    ];

    public $casts = [
        'extra_attributes' => SchemalessAttributes::class,
        'smtp_attributes' => SchemalessAttributes::class,
    ];

    public function scopeWithExtraAttributes(): Builder
    {
        return $this->extra_attributes->modelScope();
    }
    public function scopeWithSmtpAttributes(): Builder
    {
        return $this->smtp_attributes->modelScope();
    }

    public function standaloneDockers()
    {
        return $this->hasMany(StandaloneDocker::class);
    }

    public function swarmDockers()
    {
        return $this->hasMany(SwarmDocker::class);
    }

    public function privateKey()
    {
        return $this->belongsTo(PrivateKey::class);
    }

    public function settings()
    {
        return $this->hasOne(ServerSetting::class);
    }

    static public function validated()
    {
        return Server::where('team_id', session('currentTeam')->id)->whereRelation('settings', 'is_validated', true)->get();
    }
    static public function destinations(string|null $server_uuid = null)
    {
        if ($server_uuid) {
            $server = Server::where('team_id', session('currentTeam')->id)->where('uuid', $server_uuid)->firstOrFail();
            $standaloneDocker = collect($server->standaloneDockers->all());
            $swarmDocker = collect($server->swarmDockers->all());
            return $standaloneDocker->concat($swarmDocker);
        } else {
            $servers = Server::where('team_id', session('currentTeam')->id)->get();
            $standaloneDocker = $servers->map(function ($server) {
                return $server->standaloneDockers;
            })->flatten();
            $swarmDocker = $servers->map(function ($server) {
                return $server->swarmDockers;
            })->flatten();
            return $standaloneDocker->concat($swarmDocker);
        }
    }
}
