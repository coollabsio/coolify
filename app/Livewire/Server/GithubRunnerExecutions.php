<?php

namespace App\Livewire\Server;

use App\Models\GithubRunnerExecution;
use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class GithubRunnerExecutions extends Component
{
    public Server $server;

    public function mount(Server $server): void
    {
        $this->server = $server;
    }

    #[Computed]
    public function recentExecutions(): Collection
    {
        return GithubRunnerExecution::query()
            ->where('server_id', $this->server->id)
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();
    }

    public function render()
    {
        return view('livewire.server.github-runner-executions');
    }
}
