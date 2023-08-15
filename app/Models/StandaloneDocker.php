<?php

namespace App\Models;

class StandaloneDocker extends BaseModel
{
    protected $guarded = [];

    public function applications()
    {
        return $this->morphMany(Application::class, 'destination');
    }

    public function postgresqls()
    {
        return $this->morphMany(StandalonePostgresql::class, 'destination');
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
