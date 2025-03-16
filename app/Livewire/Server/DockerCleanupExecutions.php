<?php

namespace App\Livewire\Server;

use App\Models\DockerCleanupExecution;
use App\Models\Server;
use Illuminate\Support\Collection;
use Livewire\Component;

class DockerCleanupExecutions extends Component
{
    public Server $server;

    public Collection $executions;

    public ?int $selectedKey = null;

    public $selectedExecution = null;

    public bool $isPollingActive = false;

    public $currentPage = 1;

    public $logsPerPage = 100;

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},DockerCleanupDone" => 'refreshExecutions',
        ];
    }

    public function mount(Server $server)
    {
        $this->server = $server;
        $this->refreshExecutions();
    }

    public function refreshExecutions(): void
    {
        $this->executions = $this->server->dockerCleanupExecutions()
            ->orderBy('created_at', 'desc')
            ->take(20)
            ->get();

        if ($this->selectedKey) {
            $this->selectedExecution = DockerCleanupExecution::find($this->selectedKey);
            if ($this->selectedExecution && $this->selectedExecution->status !== 'running') {
                $this->isPollingActive = false;
            }
        }
    }

    public function selectExecution($key): void
    {
        if ($key == $this->selectedKey) {
            $this->selectedKey = null;
            $this->selectedExecution = null;
            $this->currentPage = 1;
            $this->isPollingActive = false;

            return;
        }
        $this->selectedKey = $key;
        $this->selectedExecution = DockerCleanupExecution::find($key);
        $this->currentPage = 1;

        if ($this->selectedExecution && $this->selectedExecution->status === 'running') {
            $this->isPollingActive = true;
        }
    }

    public function polling()
    {
        if ($this->selectedExecution && $this->isPollingActive) {
            $this->selectedExecution->refresh();
            if ($this->selectedExecution->status !== 'running') {
                $this->isPollingActive = false;
            }
        }
        $this->refreshExecutions();
    }

    public function loadMoreLogs()
    {
        $this->currentPage++;
    }

    public function getLogLinesProperty()
    {
        if (! $this->selectedExecution) {
            return collect();
        }

        if (! $this->selectedExecution->message) {
            return collect(['Waiting for execution output...']);
        }

        $lines = collect(explode("\n", $this->selectedExecution->message));

        return $lines->take($this->currentPage * $this->logsPerPage);
    }

    public function downloadLogs(int $executionId)
    {
        $execution = $this->executions->firstWhere('id', $executionId);
        if (! $execution) {
            return;
        }

        return response()->streamDownload(function () use ($execution) {
            echo $execution->message;
        }, "docker-cleanup-{$execution->uuid}.log");
    }

    public function hasMoreLogs()
    {
        if (! $this->selectedExecution || ! $this->selectedExecution->message) {
            return false;
        }
        $lines = collect(explode("\n", $this->selectedExecution->message));

        return $lines->count() > ($this->currentPage * $this->logsPerPage);
    }

    public function render()
    {
        return view('livewire.server.docker-cleanup-executions');
    }
}
