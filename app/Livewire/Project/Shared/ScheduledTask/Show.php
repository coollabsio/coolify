<?php

namespace App\Livewire\Project\Shared\ScheduledTask;

use App\Jobs\ScheduledTaskJob;
use App\Models\Application;
use App\Models\ScheduledTask;
use App\Models\Service;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Show extends Component
{
    public Application|Service $resource;

    public ScheduledTask $task;

    #[Locked]
    public array $parameters;

    #[Locked]
    public string $type;

    #[Validate(['boolean'])]
    public bool $isEnabled = false;

    #[Validate(['string', 'required'])]
    public string $name;

    #[Validate(['string', 'required'])]
    public string $command;

    #[Validate(['string', 'required'])]
    public string $frequency;

    #[Validate(['string', 'nullable'])]
    public ?string $container = null;

    #[Locked]
    public ?string $application_uuid;

    #[Locked]
    public ?string $service_uuid;

    #[Locked]
    public string $task_uuid;

    public function mount(string $task_uuid, string $project_uuid, string $environment_uuid, ?string $application_uuid = null, ?string $service_uuid = null)
    {
        try {
            $this->task_uuid = $task_uuid;
            if ($application_uuid) {
                $this->type = 'application';
                $this->application_uuid = $application_uuid;
                $this->resource = Application::ownedByCurrentTeam()->where('uuid', $application_uuid)->firstOrFail();
            } elseif ($service_uuid) {
                $this->type = 'service';
                $this->service_uuid = $service_uuid;
                $this->resource = Service::ownedByCurrentTeam()->where('uuid', $service_uuid)->firstOrFail();
            }
            $this->parameters = [
                'environment_uuid' => $environment_uuid,
                'project_uuid' => $project_uuid,
                'application_uuid' => $application_uuid,
                'service_uuid' => $service_uuid,
            ];

            $this->task = $this->resource->scheduled_tasks()->where('uuid', $task_uuid)->firstOrFail();
            $this->syncData();
        } catch (\Exception $e) {
            return handleError($e);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $isValid = validate_cron_expression($this->frequency);
            if (! $isValid) {
                $this->frequency = $this->task->frequency;
                throw new \Exception('Invalid Cron / Human expression.');
            }
            $this->task->enabled = $this->isEnabled;
            $this->task->name = str($this->name)->trim()->value();
            $this->task->command = str($this->command)->trim()->value();
            $this->task->frequency = str($this->frequency)->trim()->value();
            $this->task->container = str($this->container)->trim()->value();
            $this->task->save();
        } else {
            $this->isEnabled = $this->task->enabled;
            $this->name = $this->task->name;
            $this->command = $this->task->command;
            $this->frequency = $this->task->frequency;
            $this->container = $this->task->container;
        }
    }

    public function instantSave()
    {
        try {
            $this->syncData(true);
            $this->dispatch('success', 'Scheduled task updated.');
            $this->refreshTasks();
        } catch (\Exception $e) {
            return handleError($e);
        }
    }

    public function submit()
    {
        try {
            $this->syncData(true);
            $this->dispatch('success', 'Scheduled task updated.');
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function refreshTasks()
    {
        try {
            $this->task->refresh();
        } catch (\Exception $e) {
            return handleError($e);
        }
    }

    public function delete()
    {
        try {
            $this->task->delete();

            if ($this->type === 'application') {
                return redirect()->route('project.application.scheduled-tasks.show', $this->parameters);
            } else {
                return redirect()->route('project.service.scheduled-tasks.show', $this->parameters);
            }
        } catch (\Exception $e) {
            return handleError($e);
        }
    }

    public function executeNow()
    {
        try {
            ScheduledTaskJob::dispatch($this->task);
            $this->dispatch('success', 'Scheduled task executed.');
        } catch (\Exception $e) {
            return handleError($e);
        }
    }
}
