<?php

namespace App\Models;

class StandaloneDocker extends BaseModel
{
    protected $guarded = [];

    protected static function boot()
    {
        parent::boot();
        static::created(function ($newStandaloneDocker) {
            $server = $newStandaloneDocker->server;
            instant_remote_process([
                "docker network inspect $newStandaloneDocker->network >/dev/null 2>&1 || docker network create --driver overlay --attachable $newStandaloneDocker->network >/dev/null",
            ], $server, false);
            $connectProxyToDockerNetworks = connectProxyToNetworks($server);
            instant_remote_process($connectProxyToDockerNetworks, $server, false);
        });
    }

    public function applications()
    {
        return $this->morphMany(Application::class, 'destination');
    }

    public function postgresqls()
    {
        return $this->morphMany(StandalonePostgresql::class, 'destination');
    }

    public function redis()
    {
        return $this->morphMany(StandaloneRedis::class, 'destination');
    }

    public function mongodbs()
    {
        return $this->morphMany(StandaloneMongodb::class, 'destination');
    }

    public function mysqls()
    {
        return $this->morphMany(StandaloneMysql::class, 'destination');
    }

    public function mariadbs()
    {
        return $this->morphMany(StandaloneMariadb::class, 'destination');
    }

    public function keydbs()
    {
        return $this->morphMany(StandaloneKeydb::class, 'destination');
    }

    public function dragonflies()
    {
        return $this->morphMany(StandaloneDragonfly::class, 'destination');
    }

    public function clickhouses()
    {
        return $this->morphMany(StandaloneClickhouse::class, 'destination');
    }

    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    public function services()
    {
        return $this->morphMany(Service::class, 'destination');
    }

    public function databases()
    {
        $postgresqls = $this->postgresqls;
        $redis = $this->redis;
        $mongodbs = $this->mongodbs;
        $mysqls = $this->mysqls;
        $mariadbs = $this->mariadbs;

        return $postgresqls->concat($redis)->concat($mongodbs)->concat($mysqls)->concat($mariadbs);
    }

    public function attachedTo()
    {
        return $this->applications?->count() > 0 || $this->databases()->count() > 0;
    }
}
