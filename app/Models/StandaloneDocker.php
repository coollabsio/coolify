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
    public function postgresqls()
    {
        return $this->morphMany(StandalonePostgres::class, 'destination');
    }
    public function server()
    {
        return $this->belongsTo(Server::class);
    }
    public function attachedTo()
    {
        return $this->applications->count() > 0 || $this->databases->count() > 0;
    }
}