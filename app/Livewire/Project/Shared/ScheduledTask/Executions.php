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
    }

    public function selectTask($key): void
    {
        if ($key == $this->selectedKey) {
            $this->selectedKey = null;

            return;
        }
        $this->selectedKey = $key;
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
