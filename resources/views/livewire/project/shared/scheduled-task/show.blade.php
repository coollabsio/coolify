<div>
    <x-slot:title>
        {{ data_get_str($resource, 'name')->limit(10) }} > Scheduled Tasks | Coolify
        </x-slot>
        @if ($type === 'application')
        <h1>Scheduled Task</h1>
        <livewire:project.application.heading :application="$resource" />
        @elseif ($type === 'service')
        <livewire:project.service.navbar :service="$resource" :parameters="$parameters" />
        @endif

        <form wire:submit="submit" class="w-full">
            <div class="flex flex-col gap-2 pb-2">
                <div class="flex items-end gap-2 pt-4">
                    <h2>Scheduled Task</h2>
                    <x-forms.button type="submit">
                        Save
                    </x-forms.button>
                    <x-modal-confirmation isErrorButton buttonTitle="Delete Scheduled Task">
                        You will delete scheduled task <span class="font-bold dark:text-warning">{{ $task->name }}</span>.
                    </x-modal-confirmation>
                </div>
                <div class="w-48">
                    <x-forms.checkbox instantSave id="task.enabled" label="Enabled" />
                </div>
            </div>
            <div class="flex w-full gap-2">
                <x-forms.input placeholder="Name" id="task.name" label="Name" required />
                <x-forms.input placeholder="php artisan schedule:run" id="task.command" label="Command" required />
                <x-forms.input placeholder="0 0 * * * or daily" id="task.frequency" label="Frequency" required />
                @if ($type === 'application')
                <x-forms.input placeholder="php"
                    helper="You can leave this empty if your resource only has one container." id="task.container"
                    label="Container name" />
                @elseif ($type === 'service')
                <x-forms.input placeholder="php"
                    helper="You can leave this empty if your resource only has one service in your stack. Otherwise use the stack name, without the random generated ID. So if you have a mysql service in your stack, use mysql."
                    id="task.container" label="Service name" />
                @endif
            </div>
        </form>

        <div class="pt-4">
            <h3 class="py-4">Recent executions <span class="text-xs text-neutral-500">(click to check output)</span></h3>
            <livewire:project.shared.scheduled-task.executions :task="$task" key="{{ $task->id }}" selectedKey="" :executions="$task->executions->take(20)" />
        </div>
</div>
