<?php

namespace App\Livewire\Server;

use App\Enums\GithubRunnerStatus;
use App\Jobs\CleanupGithubRunnerJob;
use App\Models\GithubRunnerExecution;
use App\Models\Server;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Component;

class GithubRunnerExecutions extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public function mount(Server $server): void
    {
        $this->authorize('view', $server);
        $this->server = $server;
    }

    /**
     * Runners of this server, plus queued jobs of its GitHub App that wait for free capacity.
     */
    #[Computed]
    public function executions(): Collection
    {
        return $this->query()->latest('id')->limit(25)->get();
    }

    public function cancel(int $executionId)
    {
        $this->authorize('update', $this->server);

        try {
            $execution = $this->query()->whereKey($executionId)->firstOrFail();
            if (! $execution->isActive()) {
                return;
            }
            $hadServer = $execution->server_id !== null;
            $execution->finish(GithubRunnerStatus::Cancelled, 'Cancelled by a user.');
            if ($hadServer) {
                CleanupGithubRunnerJob::dispatch($execution->id);
            }
            unset($this->executions);
            $this->dispatch('success', 'Runner cancelled.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function query(): Builder
    {
        $githubAppId = $this->server->githubRunnerConfig()->value('github_app_id');

        return GithubRunnerExecution::query()->where(function (Builder $query) use ($githubAppId) {
            $query->where('server_id', $this->server->id);
            if ($githubAppId) {
                $query->orWhere(fn (Builder $queued) => $queued
                    ->whereNull('server_id')
                    ->where('status', GithubRunnerStatus::Queued)
                    ->where('github_app_id', $githubAppId));
            }
        });
    }

    public function render()
    {
        return view('livewire.server.github-runner-executions');
    }
}
