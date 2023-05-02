<?php

namespace App\Models;

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
    ];
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
}
