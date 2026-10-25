<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\ClearsGlobalSearchCache;
use App\Traits\HasSafeStringAttribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
    use Auditable, HasFactory;
    use ClearsGlobalSearchCache;
    use HasSafeStringAttribute;

    protected $fillable = [
        'name',
        'description',
        'project_id',
        'uuid',
    ];

    /**
     * Moving cards on the environment canvas is layout, not a change worth an audit event.
     */
    protected array $auditExclude = ['canvas_positions'];

    protected function casts(): array
    {
        return [
            'canvas_positions' => 'array',
        ];
    }

    protected static function booted()
    {
        static::deleting(function ($environment) {
            $shared_variables = $environment->environment_variables();
            foreach ($shared_variables as $shared_variable) {
                $shared_variable->delete();
            }
        });
    }

    /**
     * Sort order of environment names: production, staging, and development first, then the others by name.
     * resources/js/environment-order.js has the same order for the canvases.
     */
    public static function compareNames(string $first, string $second): int
    {
        $order = ['production', 'staging', 'development'];
        $rank = fn (string $name): int => ($index = array_search(strtolower($name), $order, true)) === false ? count($order) : $index;

        return $rank($first) <=> $rank($second) ?: strnatcasecmp($first, $second);
    }

    public static function ownedByCurrentTeam()
    {
        return Environment::whereRelation('project.team', 'id', currentTeam()->id)->orderBy('name');
    }

    public static function ownedByCurrentTeamAPI(int $teamId)
    {
        return Environment::whereRelation('project.team', 'id', $teamId)->orderBy('name');
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
            $this->sqlites()->count() == 0 &&
            $this->services()->count() == 0;
    }

    public function environment_variables()
    {
        return $this->hasMany(SharedEnvironmentVariable::class)->where('type', 'environment');
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

    public function sqlites()
    {
        return $this->hasMany(StandaloneSqlite::class);
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
        $sqlites = $this->sqlites;

        return $postgresqls->concat($redis)->concat($mongodbs)->concat($mysqls)->concat($mariadbs)->concat($keydbs)->concat($dragonflies)->concat($clickhouses)->concat($sqlites);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function services()
    {
        return $this->hasMany(Service::class);
    }

    public function resourceLinks(): HasMany
    {
        return $this->hasMany(ResourceLink::class);
    }

    protected function customizeName($value)
    {
        return str($value)->lower()->trim()->replace('/', '-')->toString();
    }
}
