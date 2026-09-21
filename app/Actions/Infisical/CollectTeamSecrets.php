<?php

namespace App\Actions\Infisical;

use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\SharedEnvironmentVariable;
use App\Models\Team;
use App\Services\Infisical\InfisicalPath;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

class CollectTeamSecrets
{
    use AsAction;

    /**
     * Group every syncable Coolify variable in a team by its Infisical
     * destination.
     *
     * Server-scoped shared variables are excluded: servers are orthogonal to
     * the project/environment tree and are out of scope per the spec.
     *
     * Team- and project-scoped variables are REPLICATED into every
     * environment, because Infisical has no cross-environment scope. Where a
     * key exists at both project and environment scope the environment value
     * wins, matching Coolify's own precedence.
     *
     * Bucket shape, keyed by `"{envSlug}|{path}"`:
     *
     * - `environment` — Infisical environment slug.
     * - `path` — Infisical folder, always `/`-terminated except the team root.
     * - `scope` — `team`, `project`, `environment` or `resource`. It describes
     *   the bucket's CREATION target, not the provenance of every row in it: a
     *   `/{project}/` bucket that belongs to a real environment of that project
     *   is `environment` scope even though it also carries replicated
     *   project-scoped rows.
     * - `replicated` — true when this bucket is visited once per Infisical
     *   environment for a single logical Coolify row. INVARIANT:
     *   `replicated === true` implies `create === null`.
     * - `secrets` — `key => plaintext value`, ready for `upsertSecrets()`.
     * - `rows` — `key => Model` MAP, not a Collection. Project- and
     *   environment-scoped rows share the folder `/{project}/`, so the same key
     *   legitimately arrives twice. Last-wins on BOTH `secrets` and `rows`
     *   keeps them in step; pushing onto a list would leave the pull updating
     *   the losing project row while Coolify keeps reading the environment one.
     * - `create` — `fn (string $key, string $value): ?Model`, or null when this
     *   bucket has no safe creation target. It uses `updateOrCreate`, never
     *   `create`: Postgres treats NULLs as distinct, so the unique indexes on
     *   `shared_environment_variables` do NOT stop duplicate team-scoped rows.
     *   It is null on every replicated bucket, because a replicated bucket is
     *   reached once per environment and an Infisical-only key would otherwise
     *   produce one duplicate row per environment for one logical variable.
     *   Team-scoped and cross-project `/{project}/` buckets are therefore
     *   push-and-update only; a genuinely new key discovered in Infisical can
     *   only be created at environment or resource scope.
     * - `writers` — `key => fn (string $value): void` for values that are not
     *   variable rows at all (Task 8's database credential columns). Empty
     *   here. A pull must try `rows`, then `writers`, then `create`.
     *
     * @param  array<int, string>|null  $only  Restrict the walk to these bucket
     *                                         ids (`"{envSlug}|{path}"`). Null
     *                                         walks the whole team. A deploy-time
     *                                         pull passes the three folders the
     *                                         deploying resource actually
     *                                         inherits, because the full walk is
     *                                         one HTTP round trip per bucket and
     *                                         a realistic team has well over a
     *                                         hundred of them.
     * @return array<string, array{
     *   environment: string,
     *   path: string,
     *   scope: 'team'|'project'|'environment'|'resource',
     *   replicated: bool,
     *   secrets: array<string, string>,
     *   rows: array<string, Model>,
     *   create: ?Closure,
     *   writers: array<string, Closure>
     * }>
     */
    public function handle(Team $team, ?array $only = null): array
    {
        $wanted = $only === null ? null : array_flip($only);

        $wants = fn (string $envSlug, string $path): bool => $wanted === null
            || isset($wanted["{$envSlug}|{$path}"]);

        // Cheap prefix tests so a scoped walk can skip whole projects and whole
        // environments before it issues their per-row queries.
        $touchesPath = function (string $path) use ($wanted): bool {
            if ($wanted === null) {
                return true;
            }

            foreach (array_keys($wanted) as $id) {
                [, $wantedPath] = explode('|', (string) $id, 2) + [1 => ''];

                if (str_starts_with($wantedPath, $path)) {
                    return true;
                }
            }

            return false;
        };

        $touchesEnvironment = function (string $envSlug) use ($wanted): bool {
            if ($wanted === null) {
                return true;
            }

            foreach (array_keys($wanted) as $id) {
                if (str_starts_with((string) $id, $envSlug.'|')) {
                    return true;
                }
            }

            return false;
        };

        $projects = $team->projects()->with('environments')->get();
        InfisicalPath::assertNoCollisions($projects->pluck('name')->all());

        // Environment slugs are team-wide: Infisical environments live on the
        // project (= the Coolify team), not on a Coolify project. Two
        // differently named Coolify environments that slug alike would collapse
        // into one Infisical environment, so that is an error, while two
        // projects both owning "Production" is not.
        $environmentNames = $projects
            ->flatMap(fn (Project $project) => $project->environments->pluck('name'))
            ->unique()
            ->values();

        InfisicalPath::assertNoCollisions($environmentNames->all());

        $environmentSlugs = $environmentNames
            ->map(fn (string $name) => InfisicalPath::environmentSlug($name))
            ->unique()
            ->values();

        $buckets = [];

        $ensure = function (string $envSlug, string $path, string $scope, bool $replicated, ?Closure $create) use (&$buckets): void {
            $id = "{$envSlug}|{$path}";

            if (! isset($buckets[$id])) {
                $buckets[$id] = [
                    'environment' => $envSlug,
                    'path' => $path,
                    'scope' => $scope,
                    'replicated' => $replicated,
                    'secrets' => [],
                    'rows' => [],
                    'create' => $create,
                    'writers' => [],
                ];

                return;
            }

            // Promotion. `/{project}/` is first reached as a replicated
            // project-scoped bucket in EVERY environment slug of the team, but
            // in the environments that actually belong to this project it gains
            // an unambiguous, non-duplicating creation target: one
            // environment-scoped row in that one environment.
            if ($create !== null && $buckets[$id]['create'] === null) {
                $buckets[$id]['scope'] = $scope;
                $buckets[$id]['replicated'] = $replicated;
                $buckets[$id]['create'] = $create;
            }
        };

        $add = function (string $envSlug, string $path, Model $row) use (&$buckets): void {
            $id = "{$envSlug}|{$path}";
            $buckets[$id]['secrets'][$row->key] = (string) $row->value;
            $buckets[$id]['rows'][$row->key] = $row;
        };

        // Team scope -> '/' in every environment. Rows are loaded lazily so a
        // scoped walk that does not want '/' never queries them.
        $teamRows = null;

        foreach ($environmentSlugs as $envSlug) {
            if (! $wants($envSlug, InfisicalPath::forTeam())) {
                continue;
            }

            $teamRows ??= $team->environment_variables()->where('type', 'team')->get();

            $ensure($envSlug, InfisicalPath::forTeam(), 'team', true, null);

            foreach ($teamRows as $row) {
                $add($envSlug, InfisicalPath::forTeam(), $row);
            }
        }

        foreach ($projects as $project) {
            $projectPath = InfisicalPath::forProject($project->name);

            if (! $touchesPath($projectPath)) {
                continue;
            }

            // Project scope -> '/{project}/' in every environment.
            $projectRows = null;

            foreach ($environmentSlugs as $envSlug) {
                if (! $wants($envSlug, $projectPath)) {
                    continue;
                }

                $projectRows ??= $project->environment_variables()->where('type', 'project')->get();

                $ensure($envSlug, $projectPath, 'project', true, null);

                foreach ($projectRows as $row) {
                    $add($envSlug, $projectPath, $row);
                }
            }

            foreach ($project->environments as $environment) {
                $envSlug = InfisicalPath::environmentSlug($environment->name);

                if (! $touchesEnvironment($envSlug)) {
                    continue;
                }

                // Environment scope -> same folder, this environment only.
                // Ensured and added AFTER project scope so it both promotes the
                // bucket and overwrites on key conflict.
                if ($wants($envSlug, $projectPath)) {
                    $ensure($envSlug, $projectPath, 'environment', false, $this->environmentCreator($environment, $team->id, $projectPath));

                    foreach ($environment->environment_variables()->where('type', 'environment')->get() as $row) {
                        $add($envSlug, $projectPath, $row);
                    }
                }

                $resources = collect()
                    ->concat($environment->applications)
                    ->concat($environment->services)
                    ->concat($environment->databases());

                InfisicalPath::assertNoCollisions($resources->pluck('name')->all());

                foreach ($resources as $resource) {
                    $resourcePath = InfisicalPath::forResource($project->name, $resource->name);

                    if (! $wants($envSlug, $resourcePath)) {
                        continue;
                    }

                    $ensure($envSlug, $resourcePath, 'resource', false, $this->resourceCreator($resource, $resourcePath));

                    foreach ($resource->environment_variables()->get() as $row) {
                        $add($envSlug, $resourcePath, $row);
                    }
                }
            }
        }

        return $buckets;
    }

    /**
     * Creation target for a `/{project}/` bucket in an environment that really
     * belongs to that project.
     *
     * Environment-scoped rows leave `project_id` null — matching what
     * `SharedVariables/Environment/Show.php` already writes — and the match is
     * explicit rather than left to the `(key, environment_id, team_id)` unique
     * index, which Postgres does not enforce across nullable columns.
     */
    private function environmentCreator(Environment $environment, int $teamId, string $path): Closure
    {
        return fn (string $key, string $value): ?Model => SharedEnvironmentVariable::updateOrCreate(
            [
                'key' => $key,
                'type' => 'environment',
                'team_id' => $teamId,
                'environment_id' => $environment->id,
                'project_id' => null,
            ],
            [
                'value' => $value,
                'is_infisical_managed' => true,
                'infisical_path' => $path,
            ],
        );
    }

    /**
     * Creation target for a `/{project}/{resource}/` bucket.
     *
     * `is_preview => false` is part of the MATCH, not an afterthought:
     * `Application::environment_variables()` filters on it, so a row created
     * without it is invisible to the application and the next pull would
     * recreate it forever.
     */
    private function resourceCreator(Model $resource, string $path): Closure
    {
        return fn (string $key, string $value): ?Model => EnvironmentVariable::updateOrCreate(
            [
                'key' => $key,
                'resourceable_type' => $resource->getMorphClass(),
                'resourceable_id' => $resource->id,
                'is_preview' => false,
            ],
            [
                'value' => $value,
                'is_infisical_managed' => true,
                'infisical_path' => $path,
            ],
        );
    }
}
