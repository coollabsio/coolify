<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int $id
 * @property string $name
 * @property string $uuid
 * @property string $network
 * @property int $server_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Application> $applications
 * @property-read int|null $applications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StandaloneClickhouse> $clickhouses
 * @property-read int|null $clickhouses_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StandaloneDragonfly> $dragonflies
 * @property-read int|null $dragonflies_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StandaloneKeydb> $keydbs
 * @property-read int|null $keydbs_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StandaloneMariadb> $mariadbs
 * @property-read int|null $mariadbs_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StandaloneMongodb> $mongodbs
 * @property-read int|null $mongodbs_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StandaloneMysql> $mysqls
 * @property-read int|null $mysqls_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StandalonePostgresql> $postgresqls
 * @property-read int|null $postgresqls_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StandaloneRedis> $redis
 * @property-read int|null $redis_count
 * @property-read \App\Models\Server|null $server
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Service> $services
 * @property-read int|null $services_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneDocker newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneDocker newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneDocker query()
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneDocker whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneDocker whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneDocker whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneDocker whereNetwork($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneDocker whereServerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneDocker whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StandaloneDocker whereUuid($value)
 *
 * @mixin \Eloquent
 */
class StandaloneDocker extends BaseModel
{
    use HasFactory;

    protected $guarded = [];

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
