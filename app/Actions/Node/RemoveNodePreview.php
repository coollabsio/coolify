<?php

namespace App\Actions\Node;

use App\Actions\Node\Build\CancelNodeBuild;
use App\Jobs\DeleteNodeWorkloadJob;
use App\Models\NodeBuild;
use App\Models\NodeWorkload;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Removes the preview of a pull request (decision 0025): it cancels the build, then deletes the
 * preview with its containers, volumes, domains, DNS name, and routes on every Node. A close event
 * passes no user; a delete on the Previews tab passes the user.
 */
class RemoveNodePreview
{
    use AsAction;

    public function handle(NodeWorkload $preview, ?User $requestedBy = null): void
    {
        if (! $preview->isPreview()) {
            throw new RuntimeException('Only a preview can be removed this way.');
        }
        if ($requestedBy !== null) {
            Gate::forUser($requestedBy)->authorize('delete', $preview);
        }
        self::cancelBuilds($preview);
        DeleteNodeWorkloadJob::dispatch($preview->id, $requestedBy?->id, deleteVolumes: true);
    }

    public static function cancelBuilds(NodeWorkload $preview): void
    {
        $preview->builds()->active()->get()
            ->each(fn (NodeBuild $build) => CancelNodeBuild::run($build, 'Cancelled because the preview is removed.'));
    }
}
