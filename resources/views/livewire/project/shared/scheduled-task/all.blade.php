<div class="flex flex-col gap-2">
    <div>
        <div class="flex items-center gap-2">
            <h2>Scheduled Tasks</h2>
            <x-forms.button class="btn" onclick="newTask.showModal()">+ Add</x-forms.button>
            <livewire:project.shared.scheduled-task.add />
        </div>
        <div>Scheduled Tasks for this resource.</div>
    </div>
    @forelse ($resource->scheduled_tasks as $task)
        <livewire:project.shared.scheduled-task.show wire:key="scheduled-task-{{ $task->id }}"
            :task="$task" :type="$resource->type()" />
    @empty
        <div class="text-neutral-500">No scheduled tasks found.</div>
    @endforelse
</div>
