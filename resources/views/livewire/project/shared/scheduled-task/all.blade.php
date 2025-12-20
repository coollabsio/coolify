<div>
    <div class="flex gap-2">
        <h2>{{ __('menu.scheduled_tasks') }}</h2>
        @can('update', $resource)
            <x-modal-input buttonTitle="{{ __('scheduled_task.add_button') }}" title="{{ __('scheduled_task.new_task') }}" :closeOutside="false">
                @if ($resource->type() == 'application')
                    <livewire:project.shared.scheduled-task.add :type="$resource->type()" :id="$resource->id" :containerNames="$containerNames" />
                @elseif ($resource->type() == 'service')
                    <livewire:project.shared.scheduled-task.add :type="$resource->type()" :id="$resource->id" :containerNames="$containerNames" />
                @endif
            </x-modal-input>
        @endcan
    </div>
    <div class="flex flex-col flex-wrap gap-2 pt-4">
        @forelse($resource->scheduled_tasks as $task)
            @if ($resource->type() == 'application')
                <a class="coolbox" {{ wireNavigate() }}
                    href="{{ route('project.application.scheduled-tasks', [...$parameters, 'task_uuid' => $task->uuid]) }}">
                    <span class="flex flex-col">
                        <span class="text-lg font-bold">{{ $task->name }}
                            @if ($task->container)
                                <span class="text-xs font-normal">({{ $task->container }})</span>
                            @endif
                        </span>

                        <span>{{ __('scheduled_task.frequency_prefix') }}{{ $task->frequency }}</span>
                        <span>{{ __('scheduled_task.last_run') }}{{ data_get($task->latest_log, 'status', __('scheduled_task.no_runs_yet')) }}
                        </span>
                    </span>
                </a>
            @elseif ($resource->type() == 'service')
                <a class="coolbox" {{ wireNavigate() }}
                    href="{{ route('project.service.scheduled-tasks', [...$parameters, 'task_uuid' => $task->uuid]) }}">
                    <span class="flex flex-col">
                        <span class="text-lg font-bold">{{ $task->name }}
                            @if ($task->container)
                                <span class="text-xs font-normal">({{ $task->container }})</span>
                            @endif
                        </span>
                        <span>{{ __('scheduled_task.frequency_prefix') }}{{ $task->frequency }}</span>
                        <span>{{ __('scheduled_task.last_run') }}{{ data_get($task->latest_log, 'status', __('scheduled_task.no_runs_yet')) }}
                        </span>
                    </span>
                </a>
            @endif
        @empty
            <div>{{ __('scheduled_task.no_tasks') }}</div>
        @endforelse
    </div>
</div>
