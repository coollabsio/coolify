<div class="flex flex-col gap-2" x-data="{
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
            'flex flex-col border-l-2 transition-colors p-4 cursor-pointer',
            'bg-white hover:bg-gray-100 dark:bg-coolgray-100 dark:hover:bg-coolgray-200',
            'text-black dark:text-white',
            'bg-gray-200 dark:bg-coolgray-200' =>
                data_get($execution, 'id') == $selectedKey,
            'border-green-500' => data_get($execution, 'status') === 'success',
            'border-red-500' => data_get($execution, 'status') === 'failed',
            'border-yellow-500' => data_get($execution, 'status') === 'running',
        ])>

            @if (data_get($execution, 'status') === 'running')
                <div class="absolute top-2 right-2">
                    <x-loading />
                </div>
            @endif
            <div class="text-gray-700 dark:text-gray-300 font-semibold mb-1">Status: {{ data_get($execution, 'status') }}
            </div>
            <div class="text-gray-600 dark:text-gray-400 text-sm">
                Started At: {{ $this->formatDateInServerTimezone(data_get($execution, 'created_at', now())) }}
            </div>
        </a>
        @if (strlen($execution->message) > 0)
            <x-forms.button wire:click.prevent="downloadLogs({{ data_get($execution, 'id') }})">
                Download Logs
            </x-forms.button>
        @endif
        @if (data_get($execution, 'id') == $selectedKey)
            <div class="p-4 mb-2 bg-gray-100 dark:bg-coolgray-200 rounded">
                @if (data_get($execution, 'status') === 'running')
                    <div class="flex items-center gap-2 mb-2">
                        <span>Task is running...</span>
                        <x-loading class="w-4 h-4" />
                    </div>
                @endif
                @if ($this->logLines->isNotEmpty())
                    <div>
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
                    <div>No output was recorded for this execution.</div>
                @endif
            </div>
        @endif
    @empty
        <div class="p-4 bg-gray-100 dark:bg-coolgray-100 rounded">No executions found.</div>
    @endforelse
</div>
