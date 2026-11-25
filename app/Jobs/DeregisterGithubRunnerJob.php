<?php

namespace App\Jobs;

use App\Models\GithubApp;
use App\Services\GithubRunner\GithubRunnerApi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Best effort: a failure is logged and never blocks the deletion. GitHub refuses to remove a runner
 * that still has a job, so a failed removal is retried a few times.
 */
class DeregisterGithubRunnerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const RETRY_DELAY_SECONDS = 60;

    public $tries = 5;

    public $timeout = 120;

    public function __construct(public int $githubAppId, public int $runnerId)
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $githubApp = GithubApp::query()->find($this->githubAppId);
        if (! $githubApp) {
            return;
        }

        try {
            (new GithubRunnerApi($githubApp))->deleteRunner($this->runnerId);
        } catch (\Throwable $e) {
            if ($this->attempts() < $this->tries) {
                $this->release(self::RETRY_DELAY_SECONDS * $this->attempts());

                return;
            }
            Log::warning('Could not remove GitHub runner', ['github_app_id' => $this->githubAppId, 'runner_id' => $this->runnerId, 'error' => $e->getMessage()]);
        }
    }
}
