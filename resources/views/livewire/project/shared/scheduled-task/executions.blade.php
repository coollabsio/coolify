<div class="flex flex-col-reverse gap-2">
    @forelse($executions as $execution)
            <a class="flex flex-col box" wire:click="selectTask({{ data_get($execution, 'id') }})"
                @class([
                    'border-green-500' => data_get($execution, 'status') === 'success',
                    'border-red-500' => data_get($execution, 'status') === 'failed',
                ])>
                @if (data_get($execution, 'status') === 'running')
                <div class="absolute top-2 right-2">
                    <x-loading />
                </div>
                @endif
                <div>Status: {{ data_get($execution, 'status') }}</div>
                <div>Started At: {{ data_get($execution, 'created_at') }}</div>
                @if (data_get($execution, 'id') == $selectedKey)
                    @if (data_get($execution, 'message'))
                        <div>Output: <pre>{{ data_get($execution, 'message') }}</pre></div>
                    @else
                        <div>No output was recorded for this execution.</div>
                    @endif
                @endif
            </a>
        </a>
    @empty
        <div>No executions found.</div>
    @endforelse
</div>
