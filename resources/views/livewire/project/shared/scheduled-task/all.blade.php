<div>
    <div class="flex gap-2">
        <h2>Scheduled Tasks</h2>
        <x-modal-input buttonTitle="+ Add" title="New Scheduled Task">
            <livewire:project.shared.scheduled-task.add />
        </x-modal-input>
    </div>
    <div class="flex flex-wrap gap-2 pt-4">
        @forelse($resource->scheduled_tasks as $task)
            @if ($resource->type() == 'application')
                <a class="box"
                    href="{{ route('project.application.scheduled-tasks', [...$parameters, 'task_uuid' => $task->uuid]) }}">
                    <span class="flex flex-col">
                        <span class="font-bold">{{ $task->name }}</span>
                        <span>Frequency: {{ $task->frequency }}</span>
                        <span>Last run: {{ data_get($task->latest_log, 'status', 'No runs yet') }}
                        </span>
                    </span>
                </a>
            @elseif ($resource->type() == 'service')
                <a class="box"
                    href="{{ route('project.service.scheduled-tasks', [...$parameters, 'task_uuid' => $task->uuid]) }}">
                    <span class="flex flex-col">
                        <span class="font-bold">{{ $task->name }}</span>
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
