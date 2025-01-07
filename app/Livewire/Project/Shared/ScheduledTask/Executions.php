<?php

namespace App\Livewire\Project\Shared\ScheduledTask;

use App\Models\ScheduledTask;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Executions extends Component
{
    public ScheduledTask $task;

    #[Locked]
    public int $taskId;

    #[Locked]
    public Collection $executions;

    #[Locked]
    public ?int $selectedKey = null;

    #[Locked]
    public ?string $serverTimezone = null;

    public $currentPage = 1;

    public $logsPerPage = 100;

    public $selectedExecution = null;

    public $isPollingActive = false;

    public function getListeners()
    {
        $teamId = Auth::user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ScheduledTaskDone" => 'refreshExecutions',
        ];
    }

    public function mount($taskId)
    {
        try {
            $this->taskId = $taskId;
            $this->task = ScheduledTask::findOrFail($taskId);
            $this->executions = $this->task->executions()->take(20)->get();
            $this->serverTimezone = data_get($this->task, 'application.destination.server.settings.server_timezone');
            if (! $this->serverTimezone) {
                $this->serverTimezone = data_get($this->task, 'service.destination.server.settings.server_timezone');
            }
            if (! $this->serverTimezone) {
                $this->serverTimezone = 'UTC';
            }
        } catch (\Exception $e) {
            return handleError($e);
        }
    }

    public function refreshExecutions(): void
    {
        $this->executions = $this->task->executions()->take(20)->get();
        if ($this->selectedKey) {
            $this->selectedExecution = $this->task->executions()->find($this->selectedKey);
            if ($this->selectedExecution && $this->selectedExecution->status !== 'running') {
                $this->isPollingActive = false;
            }
        }
    }

    public function selectTask($key): void
    {
        if ($key == $this->selectedKey) {
            $this->selectedKey = null;
            $this->selectedExecution = null;
            $this->currentPage = 1;
            $this->isPollingActive = false;

            return;
        }
        $this->selectedKey = $key;
        $this->selectedExecution = $this->task->executions()->find($key);
        $this->currentPage = 1;

        // Start polling if task is running
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
            return collect(['Waiting for task output...']);
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
        }, 'task-execution-'.$execution->id.'.log');
    }

    public function hasMoreLogs()
    {
        if (! $this->selectedExecution || ! $this->selectedExecution->message) {
            return false;
        }
        $lines = collect(explode("\n", $this->selectedExecution->message));

        return $lines->count() > ($this->currentPage * $this->logsPerPage);
    }

    public function formatDateInServerTimezone($date)
    {
        $serverTimezone = $this->serverTimezone;
        $dateObj = new \DateTime($date);
        try {
            $dateObj->setTimezone(new \DateTimeZone($serverTimezone));
        } catch (\Exception) {
            $dateObj->setTimezone(new \DateTimeZone('UTC'));
        }

        return $dateObj->format('Y-m-d H:i:s T');
    }
}
