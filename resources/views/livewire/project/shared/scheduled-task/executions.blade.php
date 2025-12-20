<div class="flex flex-col gap-2" wire:poll.5000ms="refreshExecutions" x-data="{
    init() {
        let interval;
        $wire.$watch('isPollingActive', value => {
            if (value) {
                interval = setInterval(() => {
                    $wire.polling();
                }, 1000);
            } else {
                if (interval) clearInterval(interval);
            }
        });
    }
}">
    @forelse($executions as $execution)
        <a wire:click="selectTask({{ data_get($execution, 'id') }})" @class([
            'relative flex flex-col border-l-2 transition-colors p-4 cursor-pointer bg-white hover:bg-gray-100 dark:bg-coolgray-100 dark:hover:bg-coolgray-200 text-black dark:text-white',
            'bg-gray-200 dark:bg-coolgray-200' => data_get($execution, 'id') == $selectedKey,
            'border-blue-500/50 border-dashed' => data_get($execution, 'status') === 'running',
            'border-error' => data_get($execution, 'status') === 'failed',
            'border-success' => data_get($execution, 'status') === 'success',
        ])>
            @if (data_get($execution, 'status') === 'running')
                <div class="absolute top-2 right-2">
                    <x-loading />
                </div>
            @endif
            <div class="flex items-center gap-2 mb-2">
                <span @class([
                    'px-3 py-1 rounded-md text-xs font-medium tracking-wide shadow-xs',
                    'bg-blue-100/80 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300 dark:shadow-blue-900/5' => data_get($execution, 'status') === 'running',
                    'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200 dark:shadow-red-900/5' => data_get($execution, 'status') === 'failed',
                    'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200 dark:shadow-green-900/5' => data_get($execution, 'status') === 'success',
                ])>
                    @php
                        $statusText = match(data_get($execution, 'status')) {
                            'success' => __('task_execution.status_success'),
                            'running' => __('task_execution.status_running'),
                            'failed' => __('task_execution.status_failed'),
                            default => ucfirst(data_get($execution, 'status'))
                        };
                    @endphp
                    {{ $statusText }}
                </span>
            </div>
            <div class="text-gray-600 dark:text-gray-400 text-sm">
                {{ __('task_execution.started') }} {{ formatDateInServerTimezone(data_get($execution, 'created_at', now()), data_get($task, 'application.destination.server') ?? data_get($task, 'service.destination.server')) }}
                @if(data_get($execution, 'status') !== 'running')
                    <br>{{ __('task_execution.ended') }} {{ formatDateInServerTimezone(data_get($execution, 'finished_at'), data_get($task, 'application.destination.server') ?? data_get($task, 'service.destination.server')) }}
                    <br>{{ __('task_execution.duration') }} {{ calculateDuration(data_get($execution, 'created_at'), data_get($execution, 'finished_at')) }}
                    <br>{{ __('task_execution.finished_ago') }} {{ \Carbon\Carbon::parse(data_get($execution, 'finished_at'))->diffForHumans() }}
                @endif
            </div>
        </a>
        @if (strlen($execution->message) > 0)
            <x-forms.button wire:click.prevent="downloadLogs({{ data_get($execution, 'id') }})">
                {{ __('task_execution.download_logs') }}
            </x-forms.button>
        @endif
        @if (data_get($execution, 'id') == $selectedKey)
            <div class="p-4 mb-2 bg-gray-100 dark:bg-coolgray-200 rounded-sm">
                @if (data_get($execution, 'status') === 'running')
                    <div class="flex items-center gap-2 mb-2">
                        <span>{{ __('task_execution.task_running') }}</span>
                        <x-loading class="w-4 h-4" />
                    </div>
                @endif
                @if ($this->logLines->isNotEmpty())
                    <div>
                        <div class="max-h-[600px] overflow-y-auto border border-gray-200 dark:border-coolgray-300 rounded p-4 bg-gray-50 dark:bg-coolgray-100 scrollbar">
                            <pre class="whitespace-pre-wrap">
@foreach ($this->logLines as $line)
{{ $line }}
@endforeach
</pre>
                        </div>
                        <div class="flex gap-2 mt-4">
                            @if ($this->hasMoreLogs())
                                <x-forms.button wire:click.prevent="loadMoreLogs" isHighlighted>
                                    {{ __('task_execution.load_more') }}
                                </x-forms.button>
                                <x-forms.button wire:click.prevent="loadAllLogs">
                                    {{ __('task_execution.load_all') }}
                                </x-forms.button>
                            @endif
                        </div>
                    </div>
                @else
                    <div>{{ __('task_execution.no_output') }}</div>
                @endif
            </div>
        @endif
    @empty
        <div class="p-4 bg-gray-100 dark:bg-coolgray-100 rounded-sm">{{ __('task_execution.no_executions') }}</div>
    @endforelse
</div>
