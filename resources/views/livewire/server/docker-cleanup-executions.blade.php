<div wire:poll.5000ms="refreshExecutions" x-data="{
    init() {
        let interval;
        $wire.$watch('isPollingActive', value => {
            clearInterval(interval);
            if (value) {
                interval = setInterval(() => $wire.polling(), 1000);
            }
        });
    }
}">
    @forelse($executions as $execution)
        <div class="border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]">
            <button type="button" wire:click="selectExecution({{ data_get($execution, 'id') }})"
                class="flex w-full items-center gap-4 px-4 py-3 text-left transition-colors hover:bg-neutral-50 dark:hover:bg-white/[0.03]">
                <x-status-badge
                    :status="match (data_get($execution, 'status')) {
                        'success' => 'Success',
                        'running' => 'In progress',
                        'failed' => 'Failed',
                        default => str(data_get($execution, 'status'))->headline(),
                    }"
                    :type="match (data_get($execution, 'status')) {
                        'success' => 'success',
                        'running' => 'warning',
                        'failed' => 'error',
                        default => 'neutral',
                    }" />
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-neutral-950 dark:text-fg">
                        {{ formatDateInServerTimezone(data_get($execution, 'created_at', now()), $server) }}
                    </p>
                    <p class="mt-0.5 text-xs text-neutral-500 dark:text-fg-dim">
                        @if (data_get($execution, 'status') === 'running')
                            Cleanup is currently running
                        @else
                            {{ calculateDuration(data_get($execution, 'created_at'), data_get($execution, 'finished_at')) }}
                            · finished {{ \Carbon\Carbon::parse(data_get($execution, 'finished_at'))->diffForHumans() }}
                        @endif
                    </p>
                </div>
                <x-chevron-down
                    class="size-4 shrink-0 text-neutral-400 transition-transform {{ data_get($execution, 'id') == $selectedKey ? 'rotate-180' : '' }}" />
            </button>

            @if (data_get($execution, 'id') == $selectedKey)
                <div class="border-t border-neutral-200 bg-neutral-50/60 p-4 dark:border-white/[0.08] dark:bg-black/20">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <p class="text-xs font-medium uppercase tracking-wide text-neutral-500 dark:text-fg-dim">
                            Execution output
                        </p>
                        @if (strlen(data_get($execution, 'message', '')) > 0)
                            <x-forms.button wire:click.prevent="downloadLogs({{ data_get($execution, 'id') }})">
                                Download logs
                            </x-forms.button>
                        @endif
                    </div>

                    @if ($this->logLines->isNotEmpty())
                        <pre class="max-h-80 overflow-auto rounded-lg bg-neutral-950 p-4 font-mono text-xs leading-5 text-neutral-300">@foreach ($this->logLines as $line)
{{ $line }}
@endforeach</pre>
                        @if ($this->hasMoreLogs())
                            <div class="mt-3">
                                <x-forms.button wire:click.prevent="loadMoreLogs">Load more</x-forms.button>
                            </div>
                        @endif
                    @else
                        <p class="text-sm text-neutral-500 dark:text-fg-dim">
                            {{ data_get($execution, 'status') === 'running'
                                ? 'Waiting for cleanup output…'
                                : 'No output was recorded for this execution.' }}
                        </p>
                    @endif

                    @if (data_get($execution, 'cleanup_log'))
                        <div class="mt-4 space-y-3">
                            @foreach (json_decode(data_get($execution, 'cleanup_log'), true) as $result)
                                <div class="overflow-hidden rounded-lg ring-1 ring-neutral-200 dark:ring-white/[0.08]">
                                    <div
                                        class="border-b border-neutral-200 bg-neutral-100 px-3 py-2 dark:border-white/[0.08] dark:bg-white/[0.04]">
                                        <code
                                            class="break-all text-xs text-neutral-700 dark:text-neutral-300">{{ data_get($result, 'command') }}</code>
                                    </div>
                                    <pre class="overflow-auto p-3 font-mono text-xs leading-5 text-neutral-600 dark:text-neutral-300">{{ filled(trim(data_get($result, 'output', ''))) ? data_get($result, 'output') : 'No output returned.' }}</pre>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </div>
    @empty
        <x-empty size="sm" title="No cleanup executions"
            description="Run a manual cleanup or wait for the next scheduled execution.">
            <x-slot:icon>
                <x-reicon name="storages" class="size-8" />
            </x-slot:icon>
        </x-empty>
    @endforelse
</div>
