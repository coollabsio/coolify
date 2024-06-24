<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property-write string $name
 * @property int $project_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $description
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Application> $applications
 * @property-read int|null $applications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StandaloneClickhouse> $clickhouses
 * @property-read int|null $clickhouses_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StandaloneDragonfly> $dragonflies
 * @property-read int|null $dragonflies_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SharedEnvironmentVariable> $environment_variables
 * @property-read int|null $environment_variables_count
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
 * @property-read \App\Models\Project|null $project
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StandaloneRedis> $redis
 * @property-read int|null $redis_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Service> $services
 * @property-read int|null $services_count
 *
 * @method static \Database\Factories\EnvironmentFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder|Environment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Environment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Environment query()
 * @method static \Illuminate\Database\Eloquent\Builder|Environment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Environment whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Environment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Environment whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Environment whereProjectId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Environment whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Environment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function booted()
    {
        static::deleting(function ($environment) {
            $shared_variables = $environment->environment_variables();
            foreach ($shared_variables as $shared_variable) {
                ray('Deleting environment shared variable: '.$shared_variable->name);
                $shared_variable->delete();
            }

        });
    }

    public function isEmpty()
    {
        return $this->applications()->count() == 0 &&
            $this->redis()->count() == 0 &&
            $this->postgresqls()->count() == 0 &&
            $this->mysqls()->count() == 0 &&
            $this->mariadbs()->count() == 0 &&
            $this->mongodbs()->count() == 0 &&
            $this->services()->count() == 0;
    }

    public function environment_variables()
    {
        return $this->hasMany(SharedEnvironmentVariable::class);
    }

    public function applications()
    {
        return $this->hasMany(Application::class);
    }

    public function postgresqls()
    {
        return $this->hasMany(StandalonePostgresql::class);
    }

    public function redis()
    {
        return $this->hasMany(StandaloneRedis::class);
    }

    public function mongodbs()
    {
        return $this->hasMany(StandaloneMongodb::class);
    }

    public function mysqls()
    {
        return $this->hasMany(StandaloneMysql::class);
    }

    public function mariadbs()
    {
        return $this->hasMany(StandaloneMariadb::class);
    }

    public function keydbs()
    {
        return $this->hasMany(StandaloneKeydb::class);
    }

    public function dragonflies()
    {
        return $this->hasMany(StandaloneDragonfly::class);
    }

    public function clickhouses()
    {
        return $this->hasMany(StandaloneClickhouse::class);
    }

    public function databases()
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

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function services()
    {
        return $this->hasMany(Service::class);
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => str($value)->lower()->trim()->replace('/', '-')->toString(),
        );
    }
}
