<?php

namespace App\Models;

use App\Traits\ClearsGlobalSearchCache;
use App\Traits\HasSafeStringAttribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
    ]
)]
class Project extends BaseModel
{
    use ClearsGlobalSearchCache;
    use HasFactory;
    use HasSafeStringAttribute;

    protected $guarded = [];

    /**
     * Get query builder for projects owned by current team.
     * If you need all projects without further query chaining, use ownedByCurrentTeamCached() instead.
     */
    public static function ownedByCurrentTeam()
    {
        return Project::whereTeamId(currentTeam()->id)->orderByRaw('LOWER(name)');
    }

    /**
     * Get all projects owned by current team (cached for request duration).
     */
    public static function ownedByCurrentTeamCached()
    {
        return once(function () {
            return Project::ownedByCurrentTeam()->get();
        });
    }

    /**
     * Get projects accessible by the current user.
     * For team owners/admins: returns all team projects.
     * For project-specific members: returns only their assigned projects.
     */
    public static function accessibleByCurrentUser()
    {
        $user = auth()->user();
        $team = currentTeam();

        if (! $user || ! $team) {
            return collect();
        }

        // Team owners/admins/regular members see all projects
        if ($user->isAdmin() || $user->isOwner()) {
            return Project::ownedByCurrentTeam()->get();
        }

        // Check if user is a project-specific member
        $projectMemberProjectIds = ProjectMember::where('user_id', $user->id)
            ->whereHas('project', fn ($q) => $q->where('team_id', $team->id))
            ->pluck('project_id');

        if ($projectMemberProjectIds->isNotEmpty()) {
            // Return only projects the user has been assigned to
            return Project::whereIn('id', $projectMemberProjectIds)
                ->orderByRaw('LOWER(name)')
                ->get();
        }

        // Regular team member - see all projects
        return Project::ownedByCurrentTeam()->get();
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
            $project->projectMembers()->delete();
            $project->projectInvitations()->delete();
            $shared_variables = $project->environment_variables();
            foreach ($shared_variables as $shared_variable) {
                $shared_variable->delete();
            }
        });
    }

    /**
     * Get all project-specific members (via pivot).
     */
    public function projectMembers(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /**
     * Get all users who are project-specific members.
     */
    public function members(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
            ->withPivot('role', 'permissions', 'invited_by', 'accepted_at')
            ->withTimestamps();
    }

    /**
     * Get pending project invitations.
     */
    public function projectInvitations(): HasMany
    {
        return $this->hasMany(ProjectInvitation::class);
    }

    /**
     * Check if a user is a project-specific member.
     */
    public function isProjectMember(User $user): bool
    {
        return $this->projectMembers()->where('user_id', $user->id)->exists();
    }

    /**
     * Get the project member record for a user.
     */
    public function getProjectMember(User $user): ?ProjectMember
    {
        return $this->projectMembers()->where('user_id', $user->id)->first();
    }

    /**
     * Check if a user can access this project.
     * Team members (owner/admin/member) always have access.
     * Project-specific members have access only to this project.
     */
    public function userCanAccess(?User $user = null): bool
    {
        $user = $user ?? auth()->user();
        if (! $user) {
            return false;
        }

        // Team owners/admins/members always have access
        if ($user->teams->contains('id', $this->team_id)) {
            return true;
        }

        // Check project-specific membership
        return $this->isProjectMember($user);
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
