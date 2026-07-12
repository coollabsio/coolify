<div>
    <form wire:submit="submit" class="w-full">
        <div class="flex flex-col gap-2 pb-2">
            <div class="flex gap-2 items-end">
                <h2>Task {{ $task->name }}</h2>
                <x-forms.button canGate="update" :canResource="$resource" type="submit">
                    Save
                </x-forms.button>
                @if ($resource->isRunning())
                    @can('update', $resource)
                        <x-forms.button type="button" wire:click="executeNow">
                            Execute Now
                        </x-forms.button>
                    @endcan
                @endif
                @can('update', $resource)
                    @if (!$isEnabled)
                        <x-forms.button wire:click="toggleEnabled" isHighlighted>Enable Task</x-forms.button>
                    @else
                        <x-forms.button wire:click="toggleEnabled">Disable Task</x-forms.button>
                    @endif
                    <x-modal-confirmation title="Confirm Scheduled Task Deletion?" isErrorButton buttonTitle="Delete"
                        submitAction="delete({{ $task->id }})" :actions="['The selected scheduled task will be permanently deleted.']" confirmationText="{{ $task->name }}"
                        confirmationLabel="Please confirm the execution of the actions by entering the Scheduled Task Name below"
                        shortConfirmationLabel="Scheduled Task Name" :confirmWithPassword="false"
                        step2ButtonText="Permanently Delete" />
                @endcan
            </div>
            <h3 class="pt-4">Configuration</h3>
            <div class="flex gap-2 w-full">
                <x-forms.input :disabled="!auth()->user()->can('update', $resource)" placeholder="Name" id="name" label="Name" required />
                <x-forms.input :disabled="!auth()->user()->can('update', $resource)" placeholder="0 0 * * * or daily" id="frequency" label="Frequency"
                    helper="You can use every_minute, hourly, daily, weekly, monthly, yearly or a cron expression." required />
                <x-forms.input :disabled="!auth()->user()->can('update', $resource)" type="number" placeholder="300" id="timeout"
                    helper="Maximum execution time in seconds (60-36000)." label="Timeout (seconds)" required />
                @if ($type === 'application')
                    <x-forms.input :disabled="!auth()->user()->can('update', $resource)" placeholder="php"
                        helper="You can leave this empty if your resource only has one container." id="container"
                        label="Container name" />
                @elseif ($type === 'service')
                    <x-forms.input :disabled="!auth()->user()->can('update', $resource)" placeholder="php"
                        helper="You can leave this empty if your resource only has one service in your stack. Otherwise use the stack name, without the random generated ID. So if you have a mysql service in your stack, use mysql."
                        id="container" label="Service name" />
                @endif
            </div>
            <x-forms.input :disabled="!auth()->user()->can('update', $resource)" placeholder="php artisan schedule:run" id="command" label="Command" required />
    </form>

    <div class="pt-4">
        <h3 class="py-4">Recent executions <span class="text-xs text-neutral-500">(click to check output)</span></h3>
        <livewire:project.shared.scheduled-task.executions :taskId="$task->id" />
    </div>
</div>
