<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $description
 * @property int $team_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Application> $applications
 * @property-read int|null $applications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StandaloneClickhouse> $clickhouses
 * @property-read int|null $clickhouses_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StandaloneDragonfly> $dragonflies
 * @property-read int|null $dragonflies_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SharedEnvironmentVariable> $environment_variables
 * @property-read int|null $environment_variables_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Environment> $environments
 * @property-read int|null $environments_count
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
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Service> $services
 * @property-read int|null $services_count
 * @property-read \App\Models\ProjectSetting|null $settings
 * @property-read \App\Models\Team|null $team
 *
 * @method static \Database\Factories\ProjectFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder|Project newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Project newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Project query()
 * @method static \Illuminate\Database\Eloquent\Builder|Project whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Project whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Project whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Project whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Project whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Project whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Project whereUuid($value)
 *
 * @mixin \Eloquent
 */
class Project extends BaseModel
{
    use HasFactory;

    protected $guarded = [];

    public static function ownedByCurrentTeam()
    {
        return Project::whereTeamId(currentTeam()->id)->orderBy('name');
    }

    protected static function booted()
    {
        static::created(function ($project) {
            ProjectSetting::create([
                'project_id' => $project->id,
            ]);
            Environment::create([
                'name' => 'production',
                'project_id' => $project->id,
            ]);
        });
        static::deleting(function ($project) {
            $project->environments()->delete();
            $project->settings()->delete();
            $shared_variables = $project->environment_variables();
            foreach ($shared_variables as $shared_variable) {
                ray('Deleting project shared variable: '.$shared_variable->name);
                $shared_variable->delete();
            }
        });
    }

    public function environment_variables()
    {
        return $this->hasMany(SharedEnvironmentVariable::class);
    }

    public function environments()
    {
        return $this->hasMany(Environment::class);
    }

    public function settings()
    {
        return $this->hasOne(ProjectSetting::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function services()
    {
        return $this->hasManyThrough(Service::class, Environment::class);
    }

    public function applications()
    {
        return $this->hasManyThrough(Application::class, Environment::class);
    }

    public function postgresqls()
    {
        return $this->hasManyThrough(StandalonePostgresql::class, Environment::class);
    }

    public function redis()
    {
        return $this->hasManyThrough(StandaloneRedis::class, Environment::class);
    }

    public function keydbs()
    {
        return $this->hasManyThrough(StandaloneKeydb::class, Environment::class);
    }

    public function dragonflies()
    {
        return $this->hasManyThrough(StandaloneDragonfly::class, Environment::class);
    }

    public function clickhouses()
    {
        return $this->hasManyThrough(StandaloneClickhouse::class, Environment::class);
    }

    public function mongodbs()
    {
        return $this->hasManyThrough(StandaloneMongodb::class, Environment::class);
    }

    public function mysqls()
    {
        return $this->hasManyThrough(StandaloneMysql::class, Environment::class);
    }

    public function mariadbs()
    {
        return $this->hasManyThrough(StandaloneMariadb::class, Environment::class);
    }

    public function resource_count()
    {
        return $this->applications()->count() + $this->postgresqls()->count() + $this->redis()->count() + $this->mongodbs()->count() + $this->mysqls()->count() + $this->mariadbs()->count() + $this->keydbs()->count() + $this->dragonflies()->count() + $this->services()->count() + $this->clickhouses()->count();
    }

    public function databases()
    {
        return $this->postgresqls()->get()->merge($this->redis()->get())->merge($this->mongodbs()->get())->merge($this->mysqls()->get())->merge($this->mariadbs()->get())->merge($this->keydbs()->get())->merge($this->dragonflies()->get())->merge($this->clickhouses()->get());
    }

    public function default_environment()
    {
        $default = $this->environments()->where('name', 'production')->first();
        if (! $default) {
            $default = $this->environments()->get()->sortBy('created_at')->first();
        }

        return $default;
    }
}
