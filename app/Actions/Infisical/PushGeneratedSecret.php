<?php

namespace App\Actions\Infisical;

use App\Jobs\InfisicalPushJob;
use App\Models\EnvironmentVariable;
use App\Models\InfisicalConnection;
use App\Models\Project;
use App\Models\SharedEnvironmentVariable;
use App\Services\Infisical\InfisicalLock;
use App\Services\Infisical\InfisicalPath;
use App\Services\Infisical\InfisicalPathCollisionException;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

class PushGeneratedSecret
{
    use AsAction;

    /**
     * Send a Coolify-generated variable up to Infisical.
     *
     * Reached only from the models' `saved` hooks, and only for writes that
     * were made inside asSystem() and NOT inside asInfisicalPull() — a human
     * write never gets this far (the saving guard rejected it) and a pull write
     * must never be echoed back up, or the two sync directions feed each other
     * forever.
     *
     * Never makes an HTTP call itself. `parse()` writes hundreds of rows during
     * a single deploy, so this only queues a per-folder job; the job re-reads
     * the whole folder from Coolify and performs one upsert for it.
     *
     * A row whose team has no enabled, adopted connection is a no-op: pushing
     * before the one upward adoption push has finished would race it.
     */
    public function handle(Model $row): void
    {
        // Cheap global short-circuit — this runs once per system variable write
        // on every instance, configured or not.
        if (! InfisicalLock::anyConnectionEnabled()) {
            return;
        }

        try {
            $destinations = $this->destinationsFor($row);
        } catch (InfisicalPathCollisionException) {
            // An unsyncable name is surfaced by adoption and by the scheduled
            // pull. It must not blow up an unrelated variable save.
            return;
        }

        if ($destinations === []) {
            return;
        }

        $connection = InfisicalConnection::query()
            ->where('team_id', $destinations['teamId'])
            ->where('is_enabled', true)
            ->whereNotNull('adopted_at')
            ->first();

        if ($connection === null) {
            return;
        }

        foreach ($destinations['folders'] as [$environmentSlug, $path]) {
            InfisicalPushJob::dispatch($connection, $environmentSlug, $path);
        }
    }

    /**
     * The Infisical folders one Coolify row belongs in, using the same
     * scope-to-path rules CollectTeamSecrets walks with.
     *
     * Team- and project-scoped shared variables are replicated into every
     * environment, because Infisical has no cross-environment scope.
     *
     * @return array{teamId: int, folders: array<int, array{0: string, 1: string}>}|array{}
     *
     * @throws InfisicalPathCollisionException
     */
    private function destinationsFor(Model $row): array
    {
        if ($row instanceof SharedEnvironmentVariable) {
            return $this->sharedDestinations($row);
        }

        if ($row instanceof EnvironmentVariable) {
            return $this->resourceDestinations($row);
        }

        return [];
    }

    /**
     * @return array{teamId: int, folders: array<int, array{0: string, 1: string}>}|array{}
     *
     * @throws InfisicalPathCollisionException
     */
    private function sharedDestinations(SharedEnvironmentVariable $row): array
    {
        // Server-scoped shared variables are out of scope entirely: servers are
        // orthogonal to the project/environment tree and are neither synced nor
        // locked.
        if ($row->type === 'server' || $row->team_id === null) {
            return [];
        }

        if ($row->type === 'team') {
            $folders = [];

            foreach ($this->environmentSlugsForTeam((int) $row->team_id) as $slug) {
                $folders[] = [$slug, InfisicalPath::forTeam()];
            }

            return ['teamId' => (int) $row->team_id, 'folders' => $folders];
        }

        if ($row->type === 'project') {
            $project = $row->project;

            if ($project === null) {
                return [];
            }

            $path = InfisicalPath::forProject($project->name);
            $folders = [];

            foreach ($this->environmentSlugsForTeam((int) $row->team_id) as $slug) {
                $folders[] = [$slug, $path];
            }

            return ['teamId' => (int) $row->team_id, 'folders' => $folders];
        }

        if ($row->type === 'environment') {
            $environment = $row->environment;
            $project = $environment?->project;

            if ($project === null) {
                return [];
            }

            return [
                'teamId' => (int) $row->team_id,
                'folders' => [[
                    InfisicalPath::environmentSlug($environment->name),
                    InfisicalPath::forProject($project->name),
                ]],
            ];
        }

        return [];
    }

    /**
     * @return array{teamId: int, folders: array<int, array{0: string, 1: string}>}|array{}
     *
     * @throws InfisicalPathCollisionException
     */
    private function resourceDestinations(EnvironmentVariable $row): array
    {
        // Preview rows are invisible to Application::environment_variables(),
        // so CollectTeamSecrets never emits them and there is nothing to push.
        if ($row->is_preview) {
            return [];
        }

        $resource = $row->resourceable;

        if ($resource === null || ! method_exists($resource, 'environment')) {
            return [];
        }

        $environment = $resource->environment;
        $project = $environment?->project;

        // ServiceApplication and ServiceDatabase have no environment of their
        // own; their variables live on the parent Service and are collected
        // there.
        if ($project === null || $project->team_id === null) {
            return [];
        }

        return [
            'teamId' => (int) $project->team_id,
            'folders' => [[
                InfisicalPath::environmentSlug($environment->name),
                InfisicalPath::forResource($project->name, $resource->name),
            ]],
        ];
    }

    /**
     * @return array<int, string>
     *
     * @throws InfisicalPathCollisionException
     */
    private function environmentSlugsForTeam(int $teamId): array
    {
        return Project::query()
            ->where('team_id', $teamId)
            ->with('environments')
            ->get()
            ->flatMap(fn (Project $project) => $project->environments->pluck('name'))
            ->unique()
            ->map(fn (string $name) => InfisicalPath::environmentSlug($name))
            ->unique()
            ->values()
            ->all();
    }
}
