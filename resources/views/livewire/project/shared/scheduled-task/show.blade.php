<div class="flex flex-col gap-6">
    <form wire:submit="submit" class="application-settings-form flex flex-col">
        <x-unsaved-bar action="submit" />
        <x-application.settings-section id="task-configuration-section" title="Task configuration"
            helper="Configure when this task runs and which command Coolify executes.">
            <x-slot:actions>
                <x-status-badge :status="$isEnabled ? 'Enabled' : 'Disabled'" :type="$isEnabled ? 'success' : 'neutral'" />
                @if ($resource->isRunning())
                    @can('update', $resource)
                        <x-forms.button type="button" wire:click="executeNow">
                            Execute now
                        </x-forms.button>
                    @endcan
                @endif
                @can('update', $resource)
                    <x-forms.button type="button" wire:click="toggleEnabled">
                        {{ $isEnabled ? 'Disable' : 'Enable' }}
                    </x-forms.button>
                    <x-modal-confirmation title="Delete scheduled task?" isErrorButton buttonTitle="Delete"
                        submitAction="delete({{ $task->id }})"
                        :actions="['The selected scheduled task will be permanently deleted.']"
                        confirmationText="{{ $task->name }}"
                        confirmationLabel="Enter the scheduled task name to confirm deletion"
                        shortConfirmationLabel="Task name" :confirmWithPassword="false"
                        step2ButtonText="Permanently delete" />
                @endcan
            </x-slot:actions>

            <div class="grid gap-4 md:grid-cols-2">
                <x-forms.input :disabled="!auth()->user()->can('update', $resource)" placeholder="Name" id="name"
                    label="Name" required />
                <x-forms.input :disabled="!auth()->user()->can('update', $resource)"
                    placeholder="0 0 * * * or daily" id="frequency" label="Schedule"
                    helper="Use every_minute, hourly, daily, weekly, monthly, yearly, or a cron expression."
                    required />
                <x-forms.input :disabled="!auth()->user()->can('update', $resource)" type="number"
                    placeholder="300" id="timeout" helper="Maximum execution time from 60 to 36,000 seconds."
                    label="Timeout (seconds)" required />
                <x-forms.input :disabled="!auth()->user()->can('update', $resource)" placeholder="php"
                    helper="Leave empty when the resource only has one container." id="container"
                    label="{{ $type === 'service' ? 'Service' : 'Container' }}" />
            </div>
            <div class="mt-4">
                <x-forms.input :disabled="!auth()->user()->can('update', $resource)"
                    placeholder="php artisan schedule:run" id="command" label="Command" required />
            </div>
        </x-application.settings-section>
    </form>

    <x-application.settings-section id="task-executions-section" title="Recent executions"
        helper="Execution history refreshes automatically while a task is running." flush>
        <livewire:project.shared.scheduled-task.executions :taskId="$task->id" />
    </x-application.settings-section>
</div>
