<?php

namespace App\Models;

class ServerSetting extends BaseModel
{
    public function server()
    {
        return $this->belongsTo(Server::class);
    }
}
