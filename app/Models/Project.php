<?php

namespace App\Models;

use OpenApi\Attributes as OA;
use Visus\Cuid2\Cuid2;

#[OA\Schema(
    description: 'Project model',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer'],
        'uuid' => ['type' => 'string'],
        'name' => ['type' => 'string'],
        'description' => ['type' => 'string'],
        'environments' => new OA\Property(
            property: 'environments',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/Environment'),
            description: 'The environments of the project.'
        ),
    ]
)]
class Project extends BaseModel
{
    protected $guarded = [];

    public static function ownedByCurrentTeam()
    {
        return Project::whereTeamId(currentTeam()->id)->orderByRaw('LOWER(name)');
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
                'uuid' => (string) new Cuid2,
            ]);
        });
        static::deleting(function ($project) {
            $project->environments()->delete();
            $project->settings()->delete();
            $shared_variables = $project->environment_variables();
            foreach ($shared_variables as $shared_variable) {
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

    public function isEmpty()
    {
        return $this->applications()->count() == 0 &&
            $this->redis()->count() == 0 &&
            $this->postgresqls()->count() == 0 &&
            $this->mysqls()->count() == 0 &&
            $this->keydbs()->count() == 0 &&
            $this->dragonflies()->count() == 0 &&
            $this->clickhouses()->count() == 0 &&
            $this->mariadbs()->count() == 0 &&
            $this->mongodbs()->count() == 0 &&
            $this->services()->count() == 0;
    }

    public function databases()
    {
        return $this->postgresqls()->get()->merge($this->redis()->get())->merge($this->mongodbs()->get())->merge($this->mysqls()->get())->merge($this->mariadbs()->get())->merge($this->keydbs()->get())->merge($this->dragonflies()->get())->merge($this->clickhouses()->get());
    }

    public function navigateTo()
    {
        if ($this->environments->count() === 1) {
            return route('project.resource.index', [
                'project_uuid' => $this->uuid,
                'environment_uuid' => $this->environments->first()->uuid,
            ]);
        }

        return route('project.show', ['project_uuid' => $this->uuid]);
    }
}
