<?php

namespace App\Actions\Node\Build;

use App\Models\NodeBuild;
use App\Models\NodeWorkload;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Starts the oldest waiting build of a workload when no other build of it is active. The build job
 * runs it when a build ends, and a cancel runs it too; a second call does nothing.
 */
class StartWaitingNodeBuild
{
    use AsAction;

    public function handle(int $workloadId): ?NodeBuild
    {
        $next = DB::transaction(function () use ($workloadId): ?NodeBuild {
            $workload = NodeWorkload::query()->withoutGlobalScopes()->lockForUpdate()->find($workloadId);
            if ($workload === null || $workload->builds()->active()->where('is_waiting', false)->exists()) {
                return null;
            }
            $next = $workload->builds()->active()->where('is_waiting', true)->orderBy('id')->first();
            $next?->update(['is_waiting' => false]);

            return $next;
        });
        if ($next !== null) {
            StartNodeBuild::dispatchBuild($next);
        }

        return $next;
    }
}
