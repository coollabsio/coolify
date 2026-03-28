<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class WorkspaceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $workspaceId = session('workspace')
            ?? context('workspace') // context fallback is needed for queued jobs where session is unavailable
            ?? '';

        $builder->where($model->qualifyColumn('workspace_id'), $workspaceId);
    }
}
