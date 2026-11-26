<?php

namespace App\Models\Scopes;

use App\Models\Environment;
use App\Models\Project;
use App\Services\Access\AccessResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Hides projects, environments, and resources that the authenticated member or API token has no grant for.
 *
 * It filters only when the actor has no team-wide grant, so owners, admins, team-wide members and tokens,
 * queue workers, and console commands see the same rows as before.
 */
class VisibleThroughAccessGrants implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $resolver = app(AccessResolver::class);
        if ($resolver->isResolving()) {
            return;
        }

        foreach ($resolver->currentLayers() as $layer) {
            if ($layer->coversWholeTeam()) {
                continue;
            }

            if ($model instanceof Project) {
                $builder->whereIn($model->qualifyColumn('id'), $layer->visibleProjectIds());
            } elseif ($model instanceof Environment) {
                $builder->whereIn($model->qualifyColumn('id'), $layer->visibleEnvironmentIds());
            } else {
                $builder->where(fn (Builder $query) => $query
                    ->whereIn($model->qualifyColumn('environment_id'), $layer->fullEnvironmentIds())
                    ->orWhereIn($model->qualifyColumn('uuid'), $layer->resourceUuids()));
            }
        }
    }
}
