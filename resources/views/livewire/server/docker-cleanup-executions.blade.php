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
    <a wire:click="selectExecution({{ data_get($execution, 'id') }})" @class([
        'flex flex-col border-l-2 transition-colors p-4 cursor-pointer bg-white hover:bg-gray-100 dark:bg-coolgray-100 dark:hover:bg-coolgray-200 text-black dark:text-white',
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
                'px-3 py-1 rounded-md text-xs font-medium tracking-wide shadow-sm',
                'bg-blue-100/80 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300 dark:shadow-blue-900/5' => data_get($execution, 'status') === 'running',
                'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200 dark:shadow-red-900/5' => data_get($execution, 'status') === 'failed',
                'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200 dark:shadow-green-900/5' => data_get($execution, 'status') === 'success',
            ])>
                @php
                $statusText = match(data_get($execution, 'status')) {
                    'success' => 'Success',
                    'running' => 'In Progress',
                    'failed' => 'Failed',
                    default => ucfirst(data_get($execution, 'status'))
                };
                @endphp
                {{ $statusText }}
            </span>
        </div>
        <div class="text-gray-600 dark:text-gray-400 text-sm">
            Started: {{ formatDateInServerTimezone(data_get($execution, 'created_at', now()), $server) }}
            @if(data_get($execution, 'status') !== 'running')
            <br>Ended: {{ formatDateInServerTimezone(data_get($execution, 'finished_at'), $server) }}
            <br>Duration: {{ calculateDuration(data_get($execution, 'created_at'), data_get($execution, 'finished_at')) }}
            <br>Finished {{ \Carbon\Carbon::parse(data_get($execution, 'finished_at'))->diffForHumans() }}
            @endif
        </div>
    </a>
    @if (strlen(data_get($execution, 'message', '')) > 0)
    <div class="flex flex-col">
        <x-forms.button wire:click.prevent="downloadLogs({{ data_get($execution, 'id') }})">
            Download Logs
        </x-forms.button>
    </div>
    @endif
    @if (data_get($execution, 'id') == $selectedKey)
    <div class="flex flex-col">
        <div class="p-4 mb-2 bg-gray-100 dark:bg-coolgray-200 rounded">
            @if (data_get($execution, 'status') === 'running')
            <div class="flex items-center gap-2 mb-2">
                <span>Execution is running...</span>
                <x-loading class="w-4 h-4" />
            </div>
            @endif
            @if ($this->logLines->isNotEmpty())
            <div>
                <h3 class="font-semibold mb-2">Status Message:</h3>
                <pre class="whitespace-pre-wrap">
@foreach ($this->logLines as $line)
{{ $line }}
@endforeach
</pre>
                <div class="flex gap-2">
                    @if ($this->hasMoreLogs())
                    <x-forms.button wire:click.prevent="loadMoreLogs" isHighlighted>
                        Load More
                    </x-forms.button>
                    @endif
                </div>
            </div>
            @else
            <div>
                <div class="font-semibold mb-2">Status Message:</div>
                <div>No output was recorded for this execution.</div>
            </div>
            @endif

            @if (data_get($execution, 'cleanup_log'))
            <div class="mt-6 space-y-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Cleanup Log:</h3>
                @foreach(json_decode(data_get($execution, 'cleanup_log'), true) as $result)
                <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-coolgray-400 bg-white dark:bg-coolgray-100 shadow-sm">
                    <div class="flex items-center gap-2 px-4 py-3 bg-gray-50 dark:bg-coolgray-200 border-b border-gray-200 dark:border-coolgray-400">
                        <svg class="h-5 w-5 flex-shrink-0 text-gray-500 dark:text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        <code class="flex-1 text-sm font-mono text-gray-700 dark:text-gray-300">{{ data_get($result, 'command') }}</code>
                    </div>
                    @php
                    $output = data_get($result, 'output');
                    $hasOutput = !empty(trim($output));
                    @endphp
                    <div class="p-4">
                        @if($hasOutput)
                        <pre class="font-mono text-sm text-gray-600 dark:text-gray-300 whitespace-pre-wrap">{{ $output }}</pre>
                        @else
                        <p class="text-sm text-gray-500 dark:text-gray-400 italic">
                            No output returned - command completed successfully
                        </p>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
            @endif
        </div>
    </div>
    @endif
    @empty
    <div class="p-4 bg-gray-100 dark:bg-coolgray-100 rounded">No executions found.</div>
    @endforelse
</div>
