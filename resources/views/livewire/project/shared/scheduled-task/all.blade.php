<div>
    <div class="flex gap-2">
        <h2>Scheduled Tasks</h2>
        <x-modal-input buttonTitle="+ Add" title="New Scheduled Task" :closeOutside="false">
            @if ($resource->type() == 'application')
                <livewire:project.shared.scheduled-task.add :type="$resource->type()" :id="$resource->id" :containerNames="$containerNames" />
            @elseif ($resource->type() == 'service')
                <livewire:project.shared.scheduled-task.add :type="$resource->type()" :id="$resource->id" :containerNames="$containerNames" />
            @endif
        </x-modal-input>
    </div>
    <div class="flex flex-col flex-wrap gap-2 pt-4">
        @forelse($resource->scheduled_tasks as $task)
            @if ($resource->type() == 'application')
                <a class="box" wire:navigate
                    href="{{ route('project.application.scheduled-tasks', [...$parameters, 'task_uuid' => $task->uuid]) }}">
                    <span class="flex flex-col">
                        <span class="text-lg font-bold">{{ $task->name }}
                            @if ($task->container)
                                <span class="text-xs font-normal">({{ $task->container }})</span>
                            @endif
                        </span>

                        <span>Frequency: {{ $task->frequency }}</span>
                        <span>Last run: {{ data_get($task->latest_log, 'status', 'No runs yet') }}
                        </span>
                    </span>
                </a>
            @elseif ($resource->type() == 'service')
                <a class="box" wire:navigate
                    href="{{ route('project.service.scheduled-tasks', [...$parameters, 'task_uuid' => $task->uuid]) }}">
                    <span class="flex flex-col">
                        <span class="text-lg font-bold">{{ $task->name }}
                            @if ($task->container)
                                <span class="text-xs font-normal">({{ $task->container }})</span>
                            @endif
                        </span>
                        <span>Frequency: {{ $task->frequency }}</span>
                        <span>Last run: {{ data_get($task->latest_log, 'status', 'No runs yet') }}
                        </span>
                    </span>
                </a>
            @endif
        @empty
            <div>No scheduled tasks configured.</div>
        @endforelse
    </div>
</div>
