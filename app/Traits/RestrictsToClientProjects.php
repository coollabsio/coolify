<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Restricts queries on the model to only projects assigned to the authenticated user
 * when that user is a client (User::is_client = true).
 *
 * The trait is a no-op for non-client users (admin, owner, member) and for unauthenticated
 * contexts (queue jobs, console commands, etc.). This makes it safe to apply broadly.
 *
 * Models that use this trait must implement getClientAccessProjectIdsConstraint(): a closure
 * applied to a query builder that filters rows whose related project_id is in the given array.
 */
trait RestrictsToClientProjects
{
    protected static function bootRestrictsToClientProjects(): void
    {
        static::addGlobalScope('clientProjectAccess', function (Builder $builder): void {
            $user = Auth::user();

            if (! $user || ! method_exists($user, 'isClient') || ! $user->isClient()) {
                return;
            }

            $projectIds = $user->assignedProjects()->pluck('projects.id')->all();

            if (empty($projectIds)) {
                $builder->whereRaw('1 = 0');

                return;
            }

            $model = $builder->getModel();
            $model->scopeAccessibleByClient($builder, $projectIds);
        });
    }

    /**
     * Each model defines how it relates to a project so the global scope can apply
     * the correct constraint. Models with a direct project_id column override this
     * to a simpler whereIn; models nested through environment use whereHas chains.
     */
    public function scopeAccessibleByClient(Builder $query, array $projectIds): Builder
    {
        return $query->whereHas('environment.project', function (Builder $q) use ($projectIds): void {
            $q->whereIn('id', $projectIds);
        });
    }
}
