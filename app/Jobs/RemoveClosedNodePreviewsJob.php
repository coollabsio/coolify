<?php

namespace App\Jobs;

use App\Actions\Node\RemoveNodePreview;
use App\Enums\NodeWorkloadDesiredState;
use App\Models\GithubApp;
use App\Models\NodeWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Removes the previews of closed pull requests of GitHub App sources once a day (decision 0025).
 * It is the safety net for a lost close event. Other sources have no API credential.
 */
class RemoveClosedNodePreviewsJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function handle(): void
    {
        NodeWorkload::query()->withoutGlobalScopes()
            ->whereNotNull('preview_of_id')
            ->whereNotNull('github_app_id')
            ->where('desired_state', '!=', NodeWorkloadDesiredState::REMOVED)
            ->with('githubApp')
            ->chunkById(100, function ($previews): void {
                foreach ($previews as $preview) {
                    try {
                        if ($this->pullRequestIsClosed($preview)) {
                            RemoveNodePreview::run($preview);
                        }
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            });
    }

    /** An unknown state, like an API error, keeps the preview. */
    private function pullRequestIsClosed(NodeWorkload $preview): bool
    {
        if (! $preview->githubApp instanceof GithubApp) {
            return false;
        }
        $repository = gitRepositorySlug($preview->git_repository);
        ['data' => $data] = githubApi($preview->githubApp, "/repos/{$repository}/pulls/{$preview->pull_request_id}", throwError: false);

        return data_get($data, 'state') === 'closed';
    }
}
