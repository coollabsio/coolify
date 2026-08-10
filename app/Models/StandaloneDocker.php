<?php

namespace App\Models;

use App\Jobs\ConnectProxyToNetworksJob;
use App\Support\ValidationPatterns;
use App\Traits\HasSafeStringAttribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Destination',
    description: 'A Docker network destination attached to a server.',
    type: 'object',
    properties: [
        new OA\Property(property: 'uuid', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'network', type: 'string'),
        new OA\Property(property: 'type', type: 'string', enum: ['standalone', 'swarm']),
        new OA\Property(property: 'server_uuid', type: 'string'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
)]
class StandaloneDocker extends BaseModel
{
    use HasFactory;
    use HasSafeStringAttribute;

    protected $fillable = [
        'server_id',
        'name',
        'network',
    ];

    protected static function boot()
    {
        parent::boot();
        static::created(function ($newStandaloneDocker) {
            if (app()->runningUnitTests()) {
                return;
            }

            $server = $newStandaloneDocker->server;
            $safeNetwork = escapeshellarg($newStandaloneDocker->network);
            instant_remote_process([
                "docker network inspect {$safeNetwork} >/dev/null 2>&1 || docker network create --driver overlay --attachable {$safeNetwork} >/dev/null",
            ], $server, false);
            ConnectProxyToNetworksJob::dispatchSync($server);
        });
    }

    public function setNetworkAttribute(string $value): void
    {
        if (! ValidationPatterns::isValidDockerNetwork($value)) {
            throw new \InvalidArgumentException('Invalid Docker network name. Must start with alphanumeric and contain only alphanumeric characters, dots, hyphens, and underscores.');
        }

        $this->attributes['network'] = $value;
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

    public static function ownedByCurrentTeam()
    {
        return static::whereHas('server', fn ($q) => $q->whereTeamId(currentTeam()->id));
    }

    public static function ownedByCurrentTeamAPI(int $teamId)
    {
        return static::whereHas('server', fn ($q) => $q->whereTeamId($teamId));
    }

    /**
     * Get the server attribute using identity map caching.
     * This intercepts lazy-loading to use cached Server lookups.
     */
    public function getServerAttribute(): ?Server
    {
        // Use eager loaded data if available
        if ($this->relationLoaded('server')) {
            return $this->getRelation('server');
        }

        // Use identity map for lazy loading
        $server = Server::findCached($this->server_id);

        // Cache in relation for future access on this instance
        if ($server) {
            $this->setRelation('server', $server);
        }

        return $server;
    }

    public function services()
    {
        return $this->morphMany(Service::class, 'destination');
    }

    public function databases(): Collection
    {
        $postgresqls = $this->postgresqls;
        $redis = $this->redis;
        $mongodbs = $this->mongodbs;
        $mysqls = $this->mysqls;
        $mariadbs = $this->mariadbs;
        $keydbs = $this->keydbs;
        $dragonflies = $this->dragonflies;
        $clickhouses = $this->clickhouses;

        return $postgresqls->concat($redis)->concat($mongodbs)->concat($mysqls)->concat($mariadbs)->concat($keydbs)->concat($dragonflies)->concat($clickhouses);
    }

    public function attachedTo()
    {
        return $this->applications()->exists() || $this->databases()->count() > 0 || $this->services()->exists();
    }
}
