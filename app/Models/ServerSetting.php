<?php

namespace App\Models;

class ServerSetting extends BaseModel
{
    protected $fillable = [
        'server_id'
    ];
    public function server()
    {
        return $this->belongsTo(Server::class);
    }
}
