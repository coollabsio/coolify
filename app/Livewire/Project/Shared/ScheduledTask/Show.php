<?php

namespace App\Livewire\Project\Shared\ScheduledTask;

use App\Jobs\ScheduledTaskJob;
use App\Models\Application;
use App\Models\ScheduledTask;
use App\Models\Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public Application|Service $resource;

    #[Locked]
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

    #[Validate(['integer', 'required', 'min:60', 'max:36000'])]
    public $timeout = 300;

    public function mount(Application|Service $resource, ScheduledTask $task): void
    {
        $this->resource = $resource;
        $this->type = $resource->type();
        $this->parameters = get_route_parameters();
        $this->task = $resource->scheduled_tasks()->whereKey($task->id)->firstOrFail();
        $this->syncData();
    }

    /**
     * @return array<int, string> Names of the task fields the save changed.
     */
    private function syncData(bool $toModel = false): array
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
            $this->task->timeout = (int) $this->timeout;
            $changedFields = auditChangedFields($this->task);
            $this->task->save();

            return $changedFields;
        } else {
            $this->isEnabled = $this->task->enabled;
            $this->name = $this->task->name;
            $this->command = auth()->user()?->can('update', $this->resource) ? $this->task->command : 'Hidden (only admins can view)';
            $this->frequency = $this->task->frequency;
            $this->container = $this->task->container;
            $this->timeout = $this->task->timeout ?? 300;
        }

        return [];
    }

    public function toggleEnabled()
    {
        try {
            $this->authorize('update', $this->resource);
            $this->authorize('update', $this->task);
            $this->isEnabled = ! $this->isEnabled;
            $this->task->enabled = $this->isEnabled;
            $changedFields = auditChangedFields($this->task);
            $this->task->save();
            $this->auditTaskUpdated($changedFields);
            $this->dispatch('success', $this->isEnabled ? 'Scheduled task enabled.' : 'Scheduled task disabled.');
        } catch (\Exception $e) {
            return handleError($e);
        }
    }

    public function instantSave()
    {
        try {
            $this->authorize('update', $this->resource);
            $this->authorize('update', $this->task);
            $this->auditTaskUpdated($this->syncData(true));
            $this->dispatch('success', 'Scheduled task updated.');
            $this->refreshTasks();
        } catch (\Exception $e) {
            return handleError($e);
        }
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->resource);
            $this->authorize('update', $this->task);
            $this->auditTaskUpdated($this->syncData(true));
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
            $this->authorize('update', $this->resource);
            $this->authorize('delete', $this->task);
            $this->task->delete();
            auditLog('ui.scheduled_task.deleted', $this->taskAuditContext());
            $this->dispatch('success', 'Scheduled task deleted.');
            $this->dispatch('scheduledTaskDeleted')->to(All::class);

            // Without true, the confirmation modal refreshes this deleted task.
            return true;
        } catch (\Exception $e) {
            return handleError($e);
        }
    }

    public function executeNow()
    {
        try {
            $this->authorize('update', $this->resource);
            $this->authorize('update', $this->task);
            ScheduledTaskJob::dispatch($this->task);
            auditLog('ui.scheduled_task.executed', [
                'team_id' => $this->resource->team()?->id,
                'resource_uuid' => $this->resource->uuid,
                'resource_name' => $this->resource->name,
                'scheduled_task_uuid' => $this->task->uuid,
                'scheduled_task_name' => $this->task->name,
            ]);
            $this->dispatch('success', 'Scheduled task executed.');
        } catch (\Exception $e) {
            return handleError($e);
        }
    }

    /**
     * Record changed task field names only; the command can contain secrets.
     *
     * @param  array<int, string>  $changedFields
     */
    private function auditTaskUpdated(array $changedFields): void
    {
        if ($changedFields === []) {
            return;
        }

        auditLog('ui.scheduled_task.updated', [
            ...$this->taskAuditContext(),
            'changed_fields' => $changedFields,
        ]);
    }

    /**
     * @return array{team_id: int|null, scheduled_task_uuid: string, scheduled_task_name: string, resource_type: string, resource_uuid: string, resource_name: string}
     */
    private function taskAuditContext(): array
    {
        return [
            'team_id' => $this->resource->team()?->id,
            'scheduled_task_uuid' => $this->task->uuid,
            'scheduled_task_name' => $this->task->name,
            'resource_type' => $this->type,
            'resource_uuid' => $this->resource->uuid,
            'resource_name' => $this->resource->name,
        ];
    }
}
