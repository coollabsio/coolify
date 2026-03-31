<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\DB;

use function is_string;

final class MemberAccessScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $memberId = session('workspace_member') ?? context('workspace_member');

        if (! is_string($memberId) || $memberId === '') {
            return;
        }

        $scopeQuery = DB::table('workspace_member_scopes')
            ->where('workspace_member_id', $memberId)
            ->where('scopeable_type', $model->getMorphClass());

        if ($scopeQuery->exists()) {
            $builder->whereIn(
                $model->qualifyColumn('id'),
                $scopeQuery->select('scopeable_id'),
            );
        }
    }
}
