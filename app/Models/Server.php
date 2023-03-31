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
    public function privateKey()
    {
        return $this->belongsTo(PrivateKey::class);
    }
    public function settings()
    {
        return $this->hasOne(ServerSetting::class);
    }
}
