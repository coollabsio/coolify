<div x-data="{ activeSection: 'general' }" class="flex min-w-0 flex-col gap-6">
    <nav aria-label="Scheduled task sections"
        class="flex min-w-0 flex-wrap gap-1 border-b border-neutral-200 pb-2 dark:border-white/[0.08]">
        @foreach (['general' => 'General', 'executions' => 'Executions', ...(auth()->user()->can('update', $resource) ? ['danger' => 'Danger Zone'] : [])] as $sectionKey => $sectionLabel)
            <button type="button" @click="activeSection = '{{ $sectionKey }}'"
                :class="activeSection === '{{ $sectionKey }}'
                    ? 'bg-coollabs/10 text-coollabs ring-1 ring-coollabs/25 dark:bg-brand/15 dark:text-brand-light dark:ring-brand-light/25'
                    : 'text-neutral-500 hover:bg-black/5 hover:text-neutral-900 dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg'"
                class="inline-flex h-8 shrink-0 cursor-pointer items-center rounded-md px-3 text-[13px] font-medium transition-colors">
                {{ $sectionLabel }}
            </button>
        @endforeach
    </nav>

    <form x-show="activeSection === 'general'" wire:submit="submit" class="application-settings-form flex flex-col">
        <x-unsaved-bar action="submit" />
        <x-application.settings-section id="task-configuration-section" title="Task configuration"
            helper="Configure when this task runs and which command Coolify executes.">
            <x-slot:actions>
                @if ($resource->isRunning())
                    @can('update', $resource)
                        <x-forms.button type="button" wire:click="executeNow">
                            Execute now
                        </x-forms.button>
                    @endcan
                @endif
                @can('update', $resource)
                    <x-forms.button type="button" wire:click="toggleEnabled" :isHighlighted="!$isEnabled">
                        {{ $isEnabled ? 'Disable' : 'Enable' }}
                    </x-forms.button>
                @endcan
            </x-slot:actions>

            <div class="grid gap-4 md:grid-cols-2">
                <x-forms.input canGate="update" :canResource="$resource" :disabled="!auth()->user()->can('update', $resource)" placeholder="Name" id="name"
                    label="Name" required />
                <x-forms.input canGate="update" :canResource="$resource" :disabled="!auth()->user()->can('update', $resource)"
                    placeholder="0 0 * * * or daily" id="frequency" label="Schedule"
                    helper="Use every_minute, hourly, daily, weekly, monthly, yearly, or a cron expression."
                    required />
                <x-forms.input canGate="update" :canResource="$resource" :disabled="!auth()->user()->can('update', $resource)" type="number"
                    placeholder="300" id="timeout" helper="Maximum execution time from 60 to 36,000 seconds."
                    label="Timeout (seconds)" required />
                <x-forms.input canGate="update" :canResource="$resource" :disabled="!auth()->user()->can('update', $resource)" placeholder="php"
                    helper="Leave empty when the resource only has one container." id="container"
                    label="{{ $type === 'service' ? 'Service' : 'Container' }}" />
            </div>
            <div class="mt-4">
                <x-forms.input canGate="update" :canResource="$resource" :disabled="!auth()->user()->can('update', $resource)"
                    placeholder="php artisan schedule:run" id="command" label="Command" required />
            </div>
        </x-application.settings-section>
    </form>

    <x-application.settings-section x-show="activeSection === 'executions'" x-cloak id="task-executions-section" title="Recent executions"
        helper="Execution history refreshes automatically while a task is running." flush>
        <livewire:project.shared.scheduled-task.executions :taskId="$task->id" />
    </x-application.settings-section>

    @can('update', $resource)
        <div x-show="activeSection === 'danger'" x-cloak class="application-settings-form">
            <x-application.settings-section title="Delete scheduled task"
                description="Permanently remove this task and its execution history.">
                <x-danger-zone title="This action cannot be undone.">
                    <p>The task stops running and cannot be restored.</p>
                    <x-slot:action>
                        <x-modal-confirmation title="Delete scheduled task?" isErrorButton buttonTitle="Delete task"
                            submitAction="delete({{ $task->id }})"
                            :actions="['The selected scheduled task will be permanently deleted.']"
                            confirmationText="{{ $task->name }}"
                            confirmationLabel="Enter the scheduled task name to confirm deletion"
                            shortConfirmationLabel="Task name" :confirmWithPassword="false"
                            step2ButtonText="Permanently delete" />
                    </x-slot:action>
                </x-danger-zone>
            </x-application.settings-section>
        </div>
    @endcan
</div>
