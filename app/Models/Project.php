<?php

namespace App\Models;

class Project extends BaseModel
{
    protected $guarded = [];

    static public function ownedByCurrentTeam()
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
        return $this->applications()->count() + $this->postgresqls()->count() + $this->redis()->count() + $this->mongodbs()->count() + $this->mysqls()->count() + $this->mariadbs()->count();
    }
}
