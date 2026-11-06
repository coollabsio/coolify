<?php

namespace App\Services\Access;

use App\Enums\AccessLevel;
use App\Enums\AccessScope;
use App\Models\Application;
use App\Models\Environment;
use App\Models\PersonalAccessToken;
use App\Models\Project;
use App\Models\Scopes\VisibleThroughAccessGrants;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\StandaloneSqlite;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Decides what a user, and the API token of the current request, can see and do.
 *
 * Owners and admins of a team have full access there. A member has the grants on their
 * `team_user.access` row; a member without stored grants keeps the old behavior (team-wide read).
 * A token with `scopes` adds a second layer: both layers must allow an action.
 */
class AccessResolver
{
    /**
     * Models that are a resource inside an environment.
     *
     * @var list<class-string<Model>>
     */
    public const RESOURCE_MODELS = [
        Application::class,
        Service::class,
        StandalonePostgresql::class,
        StandaloneMysql::class,
        StandaloneMariadb::class,
        StandaloneMongodb::class,
        StandaloneRedis::class,
        StandaloneKeydb::class,
        StandaloneDragonfly::class,
        StandaloneClickhouse::class,
        StandaloneSqlite::class,
    ];

    /** @var array<string, AccessGrants> */
    private array $layers = [];

    private bool $resolving = false;

    private int $unscopedDepth = 0;

    public function isResolving(): bool
    {
        return $this->resolving || $this->unscopedDepth > 0;
    }

    /**
     * Run the callback with VisibleThroughAccessGrants turned off, also for nested relations.
     * Only for checks that must see every row in the team, such as domain and port conflicts.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withoutVisibilityScope(Closure $callback): mixed
    {
        $this->unscopedDepth++;
        try {
            return $callback();
        } finally {
            $this->unscopedDepth--;
        }
    }

    public function flush(): void
    {
        $this->layers = [];
    }

    /**
     * The levels a member without stored grants has.
     *
     * @return list<Grant>
     */
    public static function defaultMemberGrants(): array
    {
        return [Grant::team([AccessLevel::Read])];
    }

    /**
     * @return list<Grant>|null null when the token is not limited to scopes
     */
    public static function tokenGrants(PersonalAccessToken $token): ?array
    {
        $scopes = $token->scopes;
        if ($scopes === null) {
            return null;
        }

        $levels = AccessLevel::parseMany($token->abilities ?? []);

        return array_map(
            fn (Grant $scope) => new Grant($scope->scope, $scope->uuid, $levels),
            Grant::listFrom($scopes),
        );
    }

    /**
     * @return list<Grant>|null null for owners and admins, who have no limits in the team
     */
    public function memberGrants(User $user, int $teamId): ?array
    {
        $membership = $this->membership($user, $teamId);
        if ($membership === null) {
            return [];
        }

        $role = $membership->role ?? null;
        if ($role === 'admin' || $role === 'owner') {
            return null;
        }

        $stored = $membership->access ?? null;

        return $stored === null ? self::defaultMemberGrants() : Grant::listFrom($stored);
    }

    private function isMember(User $user, int $teamId): bool
    {
        return $this->membership($user, $teamId) !== null;
    }

    /**
     * The `team_user` row of the user. It uses the loaded `teams` relation, the same data `isAdminOfTeam()`
     * reads, but it never loads that relation itself: list queries call this early in a request, and a
     * relation cached then would hide a role change made later in the same request.
     */
    private function membership(User $user, int $teamId): ?object
    {
        if ($user->relationLoaded('teams')) {
            return $user->teams->firstWhere('id', $teamId)?->pivot;
        }

        return $user->teams()->where('teams.id', $teamId)->first()?->pivot;
    }

    public function allows(User $user, AccessLevel $level, Model $target): bool
    {
        $location = $this->locate($target);
        if ($location === null) {
            return false;
        }

        [$teamId, $projectId, $environmentId, $resourceUuid] = $location;

        foreach ($this->layersFor($user, $teamId) as $layer) {
            if (! $layer->allows($level, $projectId, $environmentId, $resourceUuid)) {
                return false;
            }
        }

        return $this->isMember($user, $teamId);
    }

    /**
     * True when the user can open a project or environment: it is granted, or it contains a granted
     * environment or resource. Opening shows only the children the user can see.
     */
    public function canOpen(User $user, Project|Environment $target): bool
    {
        $location = $this->locate($target);
        if ($location === null) {
            return false;
        }

        [$teamId, $projectId, $environmentId] = $location;

        foreach ($this->layersFor($user, $teamId) as $layer) {
            $visible = $target instanceof Project
                ? in_array($projectId, $layer->visibleProjectIds(), true)
                : in_array($environmentId, $layer->visibleEnvironmentIds(), true);
            if (! $layer->coversWholeTeam() && ! $visible) {
                return false;
            }
        }

        return $this->isMember($user, $teamId);
    }

    /**
     * True when the user (and the current token) can act on the whole team, not only on some projects.
     */
    public function coversWholeTeam(User $user, int $teamId): bool
    {
        foreach ($this->layersFor($user, $teamId) as $layer) {
            if (! $layer->coversWholeTeam()) {
                return false;
            }
        }

        return true;
    }

    /**
     * True when the user (and the current token) has the level on the whole team, for actions without a target
     * inside the team, such as creating a project.
     */
    public function allowsTeamWide(User $user, AccessLevel $level, int $teamId): bool
    {
        foreach ($this->layersFor($user, $teamId) as $layer) {
            if (! $layer->allows($level)) {
                return false;
            }
        }

        return $this->isMember($user, $teamId);
    }

    /**
     * True when the user has every level of every grant, at that scope or above it.
     * A token can never get more access than the user who owns it.
     *
     * @param  list<Grant>  $grants
     */
    public function userHolds(User $user, int $teamId, array $grants): bool
    {
        $memberGrants = $this->memberGrants($user, $teamId);
        if ($memberGrants === null) {
            return true;
        }

        $layer = $this->resolve($teamId, $memberGrants);

        foreach ($grants as $grant) {
            $location = $this->locateScope($teamId, $grant);
            if ($location === null) {
                return false;
            }
            [$projectId, $environmentId, $resourceUuid] = $location;

            foreach ($grant->levels as $level) {
                if (! $layer->allows($level, $projectId, $environmentId, $resourceUuid)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Layers of the authenticated user (and token) in their current team, for list queries.
     * The token team comes first: the canvas API runs with the session, but its token is for the canvas team.
     *
     * @return list<AccessGrants>
     */
    public function currentLayers(): array
    {
        if ($this->resolving) {
            return [];
        }

        $user = auth()->user();
        if (! $user instanceof User) {
            return [];
        }

        $teamId = data_get($user->currentAccessToken(), 'team_id') ?? data_get(session('currentTeam'), 'id');
        if ($teamId === null || ! $this->isMember($user, (int) $teamId)) {
            return [];
        }

        return $this->layersFor($user, (int) $teamId);
    }

    /**
     * @return list<AccessGrants>
     */
    public function layersFor(User $user, int $teamId): array
    {
        $layers = [];

        $memberGrants = $this->memberGrants($user, $teamId);
        if ($memberGrants !== null) {
            $layers[] = $this->cachedLayer($teamId, $memberGrants);
        }

        $token = $user->is(auth()->user()) ? $user->currentAccessToken() : null;
        if ($token instanceof PersonalAccessToken && (int) $token->team_id === $teamId) {
            $tokenGrants = self::tokenGrants($token);
            if ($tokenGrants !== null) {
                $layers[] = $this->cachedLayer($teamId, $tokenGrants);
            }
        }

        return $layers;
    }

    /**
     * @param  list<Grant>  $grants
     */
    private function cachedLayer(int $teamId, array $grants): AccessGrants
    {
        $key = $teamId.':'.md5(json_encode(Grant::listToArray($grants)));

        return $this->layers[$key] ??= $this->resolve($teamId, $grants);
    }

    /**
     * Resolve grant UUIDs to IDs inside the team. Grants that point outside the team are dropped.
     *
     * @param  list<Grant>  $grants
     */
    public function resolve(int $teamId, array $grants): AccessGrants
    {
        $this->resolving = true;
        try {
            return $this->build($teamId, $grants);
        } finally {
            $this->resolving = false;
        }
    }

    /**
     * @param  list<Grant>  $grants
     */
    private function build(int $teamId, array $grants): AccessGrants
    {
        $teamLevels = [];
        $byScope = [AccessScope::Project->value => [], AccessScope::Environment->value => [], AccessScope::Resource->value => []];

        foreach ($grants as $grant) {
            if ($grant->levels === []) {
                continue;
            }
            if ($grant->scope === AccessScope::Team) {
                $teamLevels += AccessGrants::expand($grant->levels);

                continue;
            }
            $existing = $byScope[$grant->scope->value][$grant->uuid] ?? [];
            $byScope[$grant->scope->value][$grant->uuid] = $existing + AccessGrants::expand($grant->levels);
        }

        $projectLevels = [];
        $projectEnvironmentIds = [];
        if ($byScope[AccessScope::Project->value] !== []) {
            $projects = Project::withoutGlobalScope(VisibleThroughAccessGrants::class)
                ->where('team_id', $teamId)
                ->whereIn('uuid', array_keys($byScope[AccessScope::Project->value]))
                ->get(['id', 'uuid']);
            foreach ($projects as $project) {
                $projectLevels[$project->id] = $byScope[AccessScope::Project->value][$project->uuid];
            }
            $projectEnvironmentIds = Environment::withoutGlobalScope(VisibleThroughAccessGrants::class)
                ->whereIn('project_id', array_keys($projectLevels))
                ->pluck('id')
                ->all();
        }

        $environmentLevels = [];
        $environmentProjects = [];
        if ($byScope[AccessScope::Environment->value] !== []) {
            $environments = $this->teamEnvironments($teamId)
                ->whereIn('environments.uuid', array_keys($byScope[AccessScope::Environment->value]))
                ->get(['environments.id', 'environments.uuid', 'environments.project_id']);
            foreach ($environments as $environment) {
                $environmentLevels[$environment->id] = $byScope[AccessScope::Environment->value][$environment->uuid];
                $environmentProjects[$environment->id] = $environment->project_id;
            }
        }

        $resourceLevels = [];
        $resourceEnvironments = [];
        $resourceUuids = array_keys($byScope[AccessScope::Resource->value]);
        if ($resourceUuids !== []) {
            $teamEnvironmentProjects = $this->teamEnvironments($teamId)->pluck('environments.project_id', 'environments.id')->all();
            foreach (self::RESOURCE_MODELS as $modelClass) {
                $resources = $modelClass::withoutGlobalScope(VisibleThroughAccessGrants::class)
                    ->whereIn('uuid', $resourceUuids)
                    ->whereIn('environment_id', array_keys($teamEnvironmentProjects))
                    ->get(['uuid', 'environment_id']);
                foreach ($resources as $resource) {
                    $resourceLevels[$resource->uuid] = $byScope[AccessScope::Resource->value][$resource->uuid];
                    $resourceEnvironments[$resource->uuid] = $resource->environment_id;
                    $environmentProjects[$resource->environment_id] = $teamEnvironmentProjects[$resource->environment_id];
                }
            }
        }

        return new AccessGrants(
            $teamLevels,
            $projectLevels,
            $environmentLevels,
            $resourceLevels,
            $environmentProjects,
            $resourceEnvironments,
            $projectEnvironmentIds,
        );
    }

    private function teamEnvironments(int $teamId): Builder
    {
        return Environment::withoutGlobalScope(VisibleThroughAccessGrants::class)
            ->join('projects', 'projects.id', '=', 'environments.project_id')
            ->where('projects.team_id', $teamId);
    }

    /**
     * @return array{0: int, 1: int|null, 2: int|null, 3: string|null}|null team, project, environment, resource UUID
     */
    private function locate(Model $target): ?array
    {
        if ($target instanceof Project) {
            return [$target->team_id, $target->id, null, null];
        }

        $environmentId = $target instanceof Environment ? $target->id : $target->getAttribute('environment_id');
        if ($environmentId === null) {
            return null;
        }

        $this->resolving = true;
        try {
            $environment = Environment::withoutGlobalScope(VisibleThroughAccessGrants::class)
                ->with(['project' => fn ($query) => $query->withoutGlobalScope(VisibleThroughAccessGrants::class)])
                ->find($environmentId);
        } finally {
            $this->resolving = false;
        }

        if ($environment?->project === null) {
            return null;
        }

        $resourceUuid = $target instanceof Environment ? null : $target->getAttribute('uuid');

        return [$environment->project->team_id, $environment->project_id, $environment->id, $resourceUuid];
    }

    /**
     * @return array{0: int|null, 1: int|null, 2: string|null}|null project, environment, resource UUID
     */
    private function locateScope(int $teamId, Grant $grant): ?array
    {
        if ($grant->scope === AccessScope::Team) {
            return [null, null, null];
        }

        $layer = $this->resolve($teamId, [new Grant($grant->scope, $grant->uuid, [AccessLevel::Read])]);

        return match ($grant->scope) {
            AccessScope::Project => ($layer->visibleProjectIds()[0] ?? null) === null ? null : [$layer->visibleProjectIds()[0], null, null],
            AccessScope::Environment => ($layer->fullEnvironmentIds()[0] ?? null) === null ? null : [$layer->visibleProjectIds()[0], $layer->fullEnvironmentIds()[0], null],
            AccessScope::Resource => $layer->resourceUuids() === [] ? null : [$layer->visibleProjectIds()[0], $layer->visibleEnvironmentIds()[0], $grant->uuid],
        };
    }
}
