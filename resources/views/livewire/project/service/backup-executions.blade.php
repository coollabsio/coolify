<div>
    @if ($selectedExecution)
        <x-modal-input title="Backup execution" wireOpen="executionModalOpen" :wireIgnore="false" isLarge>
            <x-slot:content><span></span></x-slot:content>
            <div class="flex flex-col gap-5">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div><p class="text-xs text-neutral-500 dark:text-fg-dim">Target</p><p class="mt-1 text-sm font-medium">{{ $selectedExecution['target'] }}</p></div>
                    <div><p class="text-xs text-neutral-500 dark:text-fg-dim">Status</p><p class="mt-1 text-sm font-medium">{{ str($selectedExecution['status'])->headline() }}</p></div>
                    <div><p class="text-xs text-neutral-500 dark:text-fg-dim">Started</p><p class="mt-1 text-sm font-medium">{{ $selectedExecution['started_at']->diffForHumans() }}</p></div>
                    <div><p class="text-xs text-neutral-500 dark:text-fg-dim">Size</p><p class="mt-1 text-sm font-medium">{{ $selectedExecution['size'] ? formatBytes($selectedExecution['size']) : '-' }}</p></div>
                </div>
                @if ($selectedExecution['filename'])
                    <div><p class="text-xs text-neutral-500 dark:text-fg-dim">Backup path</p><code class="mt-1 block overflow-x-auto rounded-md bg-neutral-100 p-3 text-xs dark:bg-black/20">{{ $selectedExecution['filename'] }}</code></div>
                @endif
                @if ($selectedExecution['message'])
                    <div><p class="text-xs text-neutral-500 dark:text-fg-dim">Output</p><pre class="mt-1 max-h-80 overflow-auto rounded-md bg-neutral-100 p-3 font-mono text-xs whitespace-pre-wrap dark:bg-black/20">{{ $selectedExecution['message'] }}</pre></div>
                @endif
            </div>
        </x-modal-input>
    @endif

    <x-application.settings-section title="Executions"
        helper="Review backup runs across every database and storage target in this service." flush>
        @if ($executions->isEmpty())
            <x-empty size="sm" title="No backup executions"
                description="Execution history appears here after a backup schedule runs." icon-name="browser-terminal" />
        @else
            <div class="data-table w-full overflow-x-auto">
                <div class="data-table-header grid min-w-[820px] grid-cols-[minmax(150px,1.4fr)_100px_100px_110px_110px_90px_48px]">
                    <span>Target</span><span>Type</span><span>Schedule</span><span>Status</span><span>Started</span><span>Size</span><span class="text-right">Actions</span>
                </div>
                @foreach ($executions as $execution)
                    @php
                        $statusType = match ($execution['status']) {
                            'success' => 'success',
                            'failed' => 'error',
                            'running' => 'warning',
                            default => 'neutral',
                        };
                    @endphp
                    <div wire:key="service-backup-execution-{{ $execution['id'] }}"
                        wire:click="openExecution('{{ $execution['uuid'] }}')"
                        wire:keydown.enter="openExecution('{{ $execution['uuid'] }}')" role="button" tabindex="0"
                        class="data-table-row grid min-w-[820px] cursor-pointer grid-cols-[minmax(150px,1.4fr)_100px_100px_110px_110px_90px_48px] text-left text-[13px] text-neutral-700 dark:text-fg-dim">
                        <span class="truncate font-medium text-neutral-950 dark:text-fg" title="{{ $execution['target'] }}">{{ $execution['target'] }}</span>
                        <span>{{ $execution['type'] }}</span><span>{{ $execution['schedule'] }}</span>
                        <span><x-status-badge :status="str($execution['status'])->headline()" :type="$statusType" /></span>
                        <span>{{ $execution['started_at']->diffForHumans() }}</span>
                        <span>{{ $execution['size'] ? formatBytes($execution['size']) : '-' }}</span>
                        <span class="flex justify-end">
                            @if ($execution['download_url'])
                                <a href="{{ $execution['download_url'] }}" target="_blank" rel="noopener"
                                    @click.stop class="icon-button shrink-0" title="Download backup"
                                    aria-label="Download backup">
                                    <x-reicon name="upload" class="size-3.5 rotate-180" />
                                </a>
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </x-application.settings-section>
</div>
