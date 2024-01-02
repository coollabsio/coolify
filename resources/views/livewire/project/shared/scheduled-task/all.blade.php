<div>
    <div class="flex gap-2">
        <h2 class="pb-4">Scheduled Tasks</h2>
        <x-forms.button class="btn" onclick="newTask.showModal()">+ Add</x-forms.button>
        <livewire:project.shared.scheduled-task.add />
    </div>

    <div class="flex flex-wrap gap-2">
        @forelse($resource->scheduled_tasks as $task)
            <a class="flex flex-col box"
                href="{{ route('project.application.scheduled-tasks', [...$parameters, 'task_uuid' => $task->uuid]) }}">
                <div><span class="font-bold text-warning">{{ $task->name }}<span></div>
                <div>Frequency: {{ $task->frequency }}</div>
                <div>Last run: {{ data_get($task->latest_log, 'status', 'No runs yet') }}</div>
                <div>Next run: @todo</div>
            </a>
        @empty
            <div>No scheduled tasks configured.</div>
        @endforelse
    </div>

    {{-- @if ($type === 'service-database' && $selectedBackup)
        <div class="pt-10">
            <livewire:project.database.backup-edit key="{{ $selectedBackup->id }}" :backup="$selectedBackup" :s3s="$s3s"
                :status="data_get($database, 'status')" />
            <h3 class="py-4">Executions</h3>
            <livewire:project.database.backup-executions key="{{ $selectedBackup->id }}" :backup="$selectedBackup"
                :executions="$selectedBackup->executions" />
        </div>
    @endif --}}
</div>
