<div>
    <x-modal yesOrNo modalId="{{ $modalId }}" modalTitle="Delete Scheduled Task">
        <x-slot:modalBody>
            <p>Are you sure you want to delete this scheduled task <span
                    class="font-bold text-warning">({{ $task->name }})</span>?</p>
        </x-slot:modalBody>
    </x-modal>

    <h1>Scheduled Task</h1>
    @if ($type === 'application')
    <livewire:project.application.heading :application="$resource" />
    @elseif ($type === 'service')
    <livewire:project.service.navbar :service="$resource" :parameters="$parameters" />
    @endif

    <form wire:submit="submit">
        <div class="flex flex-col gap-2 pb-10">
            <div class="flex items-end gap-2 pt-4">
                <h2>Scheduled Task</h2>
                <x-forms.button type="submit">
                    Save
                </x-forms.button>

                <x-forms.button isError isModal modalId="{{ $modalId }}">
                    Delete
                </x-forms.button>

            </div>
        </div>

        <x-forms.input placeholder="Run cron" id="task.name" label="Name" required />
        <x-forms.input placeholder="php artisan schedule:run" id="task.command" label="Command" required />
        <x-forms.input placeholder="0 0 * * * or daily" id="task.frequency" label="Frequency" required />
        <x-forms.input placeholder="php" id="task.container" label="Container name" />
    </form>

    <div class="pt-10">
        <h3 class="py-4">Recent executions</h3>
        <livewire:project.shared.scheduled-task.executions key="{{ $task->id }}" selectedKey=""
            :executions="$task->executions->take(-20)" />
    </div>
</div>
