<?php

namespace App\Actions\Application;

use App\Models\ApplicationPreview;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * An admin approves the commit of a fork pull request that they reviewed, and Coolify deploys it.
 * The caller authorizes the `approve` ability on the preview.
 */
class ApproveForkPreviewCommit
{
    use AsAction;

    /**
     * @param  'ui'|'api'  $channel  prefix of the audit event
     * @return array{status: string, message: string, deployment_uuid?: string}
     *
     * @throws ConflictHttpException When the preview no longer waits for this commit.
     */
    public function handle(ApplicationPreview $preview, string $commit, string $channel = 'ui'): array
    {
        DB::transaction(function () use ($preview, $commit): void {
            $current = ApplicationPreview::query()->whereKey($preview->getKey())->lockForUpdate()->firstOrFail();
            if (blank($current->pending_approval_commit) || ! hash_equals($current->pending_approval_commit, $commit)) {
                throw new ConflictHttpException('The pull request changed. Review the new commit and approve it again.');
            }
            $current->approveCommit($commit);
        });
        $preview->refresh();

        auditLog("{$channel}.application.preview_commit_approved", [
            'team_id' => $preview->application->team()?->id,
            'application_uuid' => $preview->application->uuid,
            'pull_request_id' => $preview->pull_request_id,
            'commit' => $commit,
        ]);

        return queue_application_deployment(
            application: $preview->application,
            deployment_uuid: new_public_id(),
            pull_request_id: $preview->pull_request_id,
            commit: $commit,
            is_api: $channel === 'api',
            git_type: $preview->git_type,
        );
    }
}
