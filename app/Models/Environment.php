<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'Environment model',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer'],
        'name' => ['type' => 'string'],
        'project_id' => ['type' => 'integer'],
        'created_at' => ['type' => 'string'],
        'updated_at' => ['type' => 'string'],
        'description' => ['type' => 'string'],
    ]
)]
class Environment extends BaseModel
{
    protected $guarded = [];

    protected static function booted()
    {
        static::deleting(function ($environment) {
            $shared_variables = $environment->environment_variables();
            foreach ($shared_variables as $shared_variable) {
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
            $this->keydbs()->count() == 0 &&
            $this->dragonflies()->count() == 0 &&
            $this->clickhouses()->count() == 0 &&
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
