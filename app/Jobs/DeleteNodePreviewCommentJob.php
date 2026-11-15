<?php

namespace App\Jobs;

use App\Models\GithubApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Deletes the comment of a removed preview on its GitHub pull request (decision 0025), as v4 does
 * on close. It takes values, not the preview, because the preview is deleted at the same time.
 */
class DeleteNodePreviewCommentJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $githubAppId, public string $repository, public int $commentId)
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $githubApp = GithubApp::query()->find($this->githubAppId);
        if ($githubApp === null || $githubApp->is_public) {
            return;
        }
        githubApi($githubApp, "/repos/{$this->repository}/issues/comments/{$this->commentId}", 'delete', throwError: false);
    }
}
