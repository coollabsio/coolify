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
 * Best effort: a failure is logged and never blocks the deletion.
 */
class DeregisterGithubRunnerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

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
            Log::warning('Could not remove GitHub runner', ['github_app_id' => $this->githubAppId, 'runner_id' => $this->runnerId, 'error' => $e->getMessage()]);
        }
    }
}
