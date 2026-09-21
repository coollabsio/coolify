<?php

namespace App\Actions\Infisical;

use App\Models\InfisicalConnection;
use App\Models\SharedEnvironmentVariable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveInheritedSecrets
{
    use AsAction;

    /**
     * Infisical-owned variables visible to a resource, as key => value.
     *
     * Callers merge these underneath resource-level variables: a resource-level
     * variable of the same key always wins.
     *
     * Gated on the team having an enabled connection, so a team that has not
     * configured Infisical never pays for the lookup and never inherits rows.
     * Returns an empty collection until secrets have actually been synced.
     *
     * Scoping is not optional: a Coolify environment maps to a distinct native
     * Infisical environment, so only team-wide rows, rows for this resource's
     * project, and rows for this resource's own environment are visible. A
     * team_id-only filter would hand every environment every other
     * environment's secrets.
     *
     * `type => 'server'` is deliberately excluded: server-scoped shared
     * variables are out of scope for Infisical sync entirely — they are neither
     * synced up nor locked.
     *
     * @return Collection<string, string>
     */
    public function handle(Model $resource): Collection
    {
        $environment = $resource->environment;
        $teamId = $environment?->project?->team_id;

        $enabled = $teamId !== null && InfisicalConnection::query()
            ->where('team_id', $teamId)
            ->where('is_enabled', true)
            ->exists();

        if (! $enabled) {
            return collect();
        }

        $rows = SharedEnvironmentVariable::query()
            ->where('is_infisical_managed', true)
            ->where('team_id', $teamId)
            ->where(function ($query) use ($environment) {
                $query->where('type', 'team')
                    ->orWhere(fn ($q) => $q->where('type', 'project')
                        ->where('project_id', $environment->project_id))
                    ->orWhere(fn ($q) => $q->where('type', 'environment')
                        ->where('environment_id', $environment->id));
            })
            ->get();

        // Precedence: environment beats project beats team. Ascending sort means
        // the higher-precedence row is written last and wins the key. A
        // descending sort would silently invert this.
        $rank = ['team' => 0, 'project' => 1, 'environment' => 2];

        return $rows->sortBy(fn ($row) => $rank[$row->type] ?? 0)
            ->mapWithKeys(fn ($row) => [$row->key => $row->value]);
    }
}
