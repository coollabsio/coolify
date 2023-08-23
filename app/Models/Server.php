<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Spatie\SchemalessAttributes\Casts\SchemalessAttributes;
use Spatie\SchemalessAttributes\SchemalessAttributesTrait;

class Server extends BaseModel
{
    use SchemalessAttributesTrait;

    protected static function booted()
    {
        static::created(function ($server) {
            ServerSetting::create([
                'server_id' => $server->id,
            ]);
            StandaloneDocker::create([
                'name' => 'coolify',
                'network' => 'coolify',
                'server_id' => $server->id,
            ]);
        });
        static::deleting(function ($server) {
            $server->settings()->delete();
        });
    }

    public $casts = [
        'proxy' => SchemalessAttributes::class,
    ];
    protected $schemalessAttributes = [
        'proxy',
    ];
    protected $guarded = [];

    static public function isReachable()
    {
        return Server::ownedByCurrentTeam()->whereRelation('settings', 'is_reachable', true);
    }

    static public function ownedByCurrentTeam(array $select = ['*'])
    {
        $teamId = currentTeam()->id;
        $selectArray = collect($select)->concat(['id']);
        return Server::whereTeamId($teamId)->with('settings')->select($selectArray->all())->orderBy('name');
    }

    static public function isUsable()
    {
        return Server::ownedByCurrentTeam()->whereRelation('settings', 'is_reachable', true)->whereRelation('settings', 'is_usable', true);
    }

    static public function destinationsByServer(string $server_id)
    {
        $server = Server::ownedByCurrentTeam()->get()->where('id', $server_id)->firstOrFail();
        $standaloneDocker = collect($server->standaloneDockers->all());
        $swarmDocker = collect($server->swarmDockers->all());
        return $standaloneDocker->concat($swarmDocker);
    }



    public function settings()
    {
        return $this->hasOne(ServerSetting::class);
    }

    public function scopeWithProxy(): Builder
    {
        return $this->proxy->modelScope();
    }

    public function isEmpty()
    {
        if ($this->applications()->count() === 0) {
            return true;
        }
        return false;
    }

    public function applications()
    {
        return $this->destinations()->map(function ($standaloneDocker) {
            return $standaloneDocker->applications;
        })->flatten();
    }

    public function destinations()
    {
        $standalone_docker = $this->hasMany(StandaloneDocker::class)->get();
        $swarm_docker = $this->hasMany(SwarmDocker::class)->get();
        return $standalone_docker->concat($swarm_docker);
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

    public function muxFilename()
    {
        return "{$this->ip}_{$this->port}_{$this->user}";
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
