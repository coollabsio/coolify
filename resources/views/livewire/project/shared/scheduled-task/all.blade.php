<div>
    <div class="flex gap-2">
        <h2>Scheduled Tasks</h2>
        <x-modal-input buttonTitle="+ Add" title="New Scheduled Task">
            <livewire:project.shared.scheduled-task.add />
        </x-modal-input>
    </div>
    <div class="flex flex-wrap gap-2 pt-4">
        @forelse($resource->scheduled_tasks as $task)
            <a class="flex flex-col box"
                @if ($resource->type() == 'application') href="{{ route('project.application.scheduled-tasks', [...$parameters, 'task_uuid' => $task->uuid]) }}">
                @elseif  ($resource->type() == 'service')
                href="{{ route('project.service.scheduled-tasks', [...$parameters, 'task_uuid' => $task->uuid]) }}"> @endif
                <div><span class="font-bold dark:text-warning">{{ $task->name }}<span>
    </div>
    <div>Frequency: {{ $task->frequency }}</div>
    <div>Last run: {{ data_get($task->latest_log, 'status', 'No runs yet') }}</div>
    </a>
@empty
    <div>No scheduled tasks configured.</div>
    @endforelse
</div>
</div>
