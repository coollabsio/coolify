<div class="flex flex-col gap-4">
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
        @if (data_get($execution, 'id') == $selectedKey)
            <div class="p-4 mb-2 bg-gray-100 dark:bg-coolgray-200 rounded">
                @if (data_get($execution, 'message'))
                    <div>
                        <pre class="whitespace-pre-wrap">{{ data_get($execution, 'message') }}</pre>
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
