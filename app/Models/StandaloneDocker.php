<?php

namespace App\Models;

class StandaloneDocker extends BaseModel
{
    protected $fillable = [
        'name',
        'network',
        'server_id',
    ];
    public function applications()
    {
        return $this->morphMany(Application::class, 'destination');
    }
    public function server()
    {
        return $this->belongsTo(Server::class);
    }
}
