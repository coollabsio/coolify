<div wire:poll.5000ms="refreshExecutions" x-data="{
    init() {
        let interval;
        $wire.$watch('isPollingActive', value => {
            if (value) {
                interval = setInterval(() => $wire.polling(), 1000);
            } else if (interval) {
                clearInterval(interval);
            }
        });
    }
}">
    @forelse($executions as $execution)
        @php
            $executionStatus = data_get($execution, 'status');
            $statusType = match ($executionStatus) {
                'success' => 'success',
                'failed' => 'error',
                'running' => 'warning',
                default => 'neutral',
            };
            $statusText = match ($executionStatus) {
                'success' => 'Success',
                'running' => 'Running',
                'failed' => 'Failed',
                default => ucfirst($executionStatus),
            };
            $server = data_get($task, 'application.destination.server')
                ?? data_get($task, 'service.destination.server');
        @endphp
        <div class="border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]"
            wire:key="task-execution-{{ data_get($execution, 'id') }}">
            <button type="button" wire:click="selectTask({{ data_get($execution, 'id') }})"
                class="flex w-full items-center gap-3 px-4 py-3.5 text-left transition-colors hover:bg-neutral-50 dark:hover:bg-white/[0.025]">
                <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 ring-1 ring-neutral-200 dark:bg-white/[0.05] dark:text-fg-dim dark:ring-white/[0.07]">
                    @if ($executionStatus === 'running')
                        <x-loading class="size-4" />
                    @else
                        <x-reicon name="terminal" class="size-4" />
                    @endif
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-[13px] font-medium text-black dark:text-fg">
                        {{ formatDateInServerTimezone(data_get($execution, 'created_at', now()), $server) }}
                    </p>
                    <p class="mt-0.5 text-xs text-neutral-500 dark:text-fg-dim">
                        @if ($executionStatus === 'running')
                            In progress
                        @else
                            {{ calculateDuration(data_get($execution, 'created_at'), data_get($execution, 'finished_at')) }}
                            · finished {{ \Carbon\Carbon::parse(data_get($execution, 'finished_at'))->diffForHumans() }}
                        @endif
                    </p>
                </div>
                <x-status-badge :status="$statusText" :type="$statusType" />
            </button>

            @if (data_get($execution, 'id') == $selectedKey)
                <div class="border-t border-neutral-200 bg-neutral-50 p-4 dark:border-white/[0.07] dark:bg-black/10">
                    @if ($this->logLines->isNotEmpty())
                        <pre
                            class="max-h-[600px] overflow-y-auto whitespace-pre-wrap rounded-lg bg-neutral-950 p-4 font-mono text-xs leading-5 text-neutral-200 ring-1 ring-black/20 scrollbar">@foreach ($this->logLines as $line)
{{ $line }}
@endforeach</pre>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @if ($this->hasMoreLogs())
                                <x-forms.button wire:click.prevent="loadMoreLogs">
                                    Load more
                                </x-forms.button>
                                <x-forms.button wire:click.prevent="loadAllLogs">
                                    Load all
                                </x-forms.button>
                            @endif
                            @if (strlen($execution->message) > 0)
                                <x-forms.button wire:click.prevent="downloadLogs({{ data_get($execution, 'id') }})">
                                    Download logs
                                </x-forms.button>
                            @endif
                        </div>
                    @else
                        <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                            {{ $executionStatus === 'running' ? 'Waiting for output…' : 'No output was recorded for this execution.' }}
                        </p>
                    @endif
                </div>
            @endif
        </div>
    @empty
        <x-empty size="sm" title="No executions yet"
            description="Run this task now or wait for its next scheduled execution.">
            <x-slot:icon>
                <x-reicon name="terminal" class="size-8" />
            </x-slot:icon>
        </x-empty>
    @endforelse
</div>
