<div>
    <x-application.settings-section id="scheduled-tasks-section" title="Scheduled tasks"
        helper="Run commands inside this resource automatically using a cron schedule." flush>
        <x-slot:actions>
            @can('update', $resource)
                <x-modal-input title="New scheduled task" :closeOutside="false">
                    <x-slot:content>
                        <button type="button"
                            class="button bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!">
                            <x-reicon name="plus" class="size-3.5" />
                            Add task
                        </button>
                    </x-slot:content>
                    <livewire:project.shared.scheduled-task.add :type="$resource->type()" :id="$resource->id"
                        :containerNames="$containerNames" />
                </x-modal-input>
            @endcan
        </x-slot:actions>

        @forelse($resource->scheduled_tasks as $task)
            @php
                $status = data_get($task->latest_log, 'status');
                $statusType = match ($status) {
                    'success' => 'success',
                    'failed' => 'error',
                    'running' => 'warning',
                    default => 'neutral',
                };
                $statusLabel = match ($status) {
                    'success' => 'Success',
                    'failed' => 'Failed',
                    'running' => 'Running',
                    default => 'Not run yet',
                };
                $taskRoute = $resource->type() === 'application'
                    ? 'project.application.scheduled-tasks'
                    : 'project.service.scheduled-tasks';
            @endphp
            <a class="group flex items-center gap-3 border-b border-neutral-200 px-4 py-3.5 transition-colors last:border-b-0 hover:bg-neutral-50 dark:border-white/[0.07] dark:hover:bg-white/[0.025]"
                {{ wireNavigate() }}
                href="{{ route($taskRoute, [...$parameters, 'task_uuid' => $task->uuid]) }}">
                <div
                    class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 ring-1 ring-neutral-200 dark:bg-white/[0.05] dark:text-fg-dim dark:ring-white/[0.07]">
                    <x-reicon name="terminal" class="size-[18px]" />
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h4 class="truncate text-sm font-semibold text-black dark:text-fg">{{ $task->name }}</h4>
                        @if ($task->container)
                            <code
                                class="rounded bg-neutral-100 px-1.5 py-0.5 font-mono text-[11px] text-neutral-600 dark:bg-white/[0.05] dark:text-fg-dim">{{ $task->container }}</code>
                        @endif
                    </div>
                    <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                        Runs on
                        <code
                            class="ml-1 rounded bg-neutral-100 px-1.5 py-0.5 font-mono text-xs text-neutral-700 dark:bg-white/[0.05] dark:text-fg-dim">{{ $task->frequency }}</code>
                    </p>
                </div>
                <x-status-badge :status="$statusLabel" :type="$statusType" />
            </a>
        @empty
            <x-empty title="No scheduled tasks"
                description="Create a task to run maintenance commands, scripts, or recurring jobs automatically.">
                <x-slot:icon>
                    <x-reicon name="terminal" class="size-8" />
                </x-slot:icon>
            </x-empty>
        @endforelse
    </x-application.settings-section>
</div>
