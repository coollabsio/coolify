<div x-on:open-backup-download.window="window.location.href = $event.detail.url">
    @if (count($pendingDownloads) > 0)
        <div wire:poll.3s="checkDownloads" class="hidden"></div>
    @endif
    @if ($executions->onFirstPage())
        <div wire:poll.5000ms class="hidden"></div>
    @endif
    @php
        $server = $service->server;
    @endphp
    @if ($selectedExecution)
        <x-modal-input title="Backup execution" wireOpen="executionModalOpen" :wireIgnore="false" isLarge>
            <x-slot:content><span></span></x-slot:content>
            <div class="flex flex-col gap-5">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div><p class="text-xs text-neutral-500 dark:text-fg-dim">Target</p><p class="mt-1 text-sm font-medium">{{ $selectedExecution['target'] }}</p></div>
                    <div><p class="text-xs text-neutral-500 dark:text-fg-dim">Status</p><p class="mt-1 text-sm font-medium">{{ str($selectedExecution['status'])->headline() }}</p></div>
                    <div><p class="text-xs text-neutral-500 dark:text-fg-dim">Started</p><p class="mt-1 text-sm font-medium" title="{{ $selectedExecution['started_at']->diffForHumans() }}">{{ formatDateInServerTimezone($selectedExecution['started_at'], $server) }}</p></div>
                    <div><p class="text-xs text-neutral-500 dark:text-fg-dim">Size</p><p class="mt-1 text-sm font-medium">{{ $selectedExecution['size'] ? formatBytes($selectedExecution['size']) : '-' }}</p></div>
                </div>
                @if ($selectedExecution['filename'])
                    <div>
                        <p class="text-xs text-neutral-500 dark:text-fg-dim">Backup path</p>
                        <div class="mt-1 flex items-center gap-2 rounded-md bg-neutral-100 p-3 dark:bg-black/20">
                            <code class="min-w-0 flex-1 overflow-x-auto text-xs">{{ $selectedExecution['filename'] }}</code>
                            <x-copy-button :value="$selectedExecution['filename']" label="Copy backup path" />
                        </div>
                    </div>
                @endif
                @if ($selectedExecution['message'])
                    <div><p class="text-xs text-neutral-500 dark:text-fg-dim">Output</p><pre class="mt-1 max-h-80 overflow-auto rounded-md bg-neutral-100 p-3 font-mono text-xs whitespace-pre-wrap dark:bg-black/20">{{ $selectedExecution['message'] }}</pre></div>
                @endif
            </div>
        </x-modal-input>
    @endif

    <x-application.settings-section title="Executions"
        helper="Review backup runs across every database and storage target in this service." flush>
        @if ($executions->total() > 10)
            <x-slot:actions>
                <x-page-size-select model="perPage" livewire />
            </x-slot:actions>
        @endif
        @if ($executions->isEmpty())
            <x-empty size="sm" title="No backup executions"
                description="Execution history appears here after a backup schedule runs." icon-name="browser-terminal" />
        @else
            <div class="data-table relative w-full overflow-x-auto">
                <x-table.loading target="previousPage,nextPage,setPage,perPage,sortExecutions" text="Loading executions..." />
                <div class="flex min-w-[64rem] flex-col">
                <div class="data-table-header service-backup-table-grid">
                    <x-table.sort-header label="Target" asc="target_asc" desc="target_desc" action="sortExecutions" :current="$sortBy" />
                    <x-table.sort-header label="Type" asc="type_asc" desc="type_desc" action="sortExecutions" :current="$sortBy" />
                    <x-table.sort-header label="Schedule" asc="schedule_asc" desc="schedule_desc" action="sortExecutions" :current="$sortBy" />
                    <x-table.sort-header label="Status" asc="status_asc" desc="status_desc" action="sortExecutions" :current="$sortBy" />
                    <x-table.sort-header label="Started" asc="started_asc" desc="started_desc" first="desc" action="sortExecutions" :current="$sortBy" />
                    <x-table.sort-header label="Size" asc="size_asc" desc="size_desc" first="desc" action="sortExecutions" :current="$sortBy" />
                    <span class="text-right">Actions</span>
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
                        class="data-table-row service-backup-table-grid cursor-pointer text-left text-[13px] text-neutral-700 dark:text-fg-dim">
                        <span class="flex min-w-0 items-center gap-2 font-medium text-neutral-950 dark:text-fg">
                            <span class="truncate" title="{{ $execution['target'] }}">{{ $execution['target'] }}</span>
                            @if ($execution['s3_tooltip'])
                                <span tabindex="0" data-tooltip="{{ $execution['s3_tooltip'] }}"
                                    aria-label="{{ $execution['s3_tooltip'] }}"
                                    class="shrink-0 rounded-full bg-neutral-100 px-1.5 py-0.5 text-[10px] font-semibold leading-none text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">S3</span>
                            @endif
                        </span>
                        <span>{{ $execution['type'] }}</span><span>{{ $execution['schedule'] }}</span>
                        <span><x-status-badge :status="str($execution['status'])->headline()" :type="$statusType" /></span>
                        <span>
                            <span tabindex="0" data-tooltip="{{ formatDateInServerTimezone($execution['started_at'], $server) }}">{{ $execution['started_at']->diffForHumans() }}</span>
                        </span>
                        <span>{{ $execution['size'] ? formatBytes($execution['size']) : '-' }}</span>
                        <span class="flex justify-end">
                            @if ($execution['download_url'] && $s3Downloads)
                                <button type="button" wire:click.stop="downloadExecution('{{ $execution['uuid'] }}')"
                                    class="icon-button shrink-0" title="Download backup" aria-label="Download backup">
                                    <x-reicon name="upload" class="size-3.5 rotate-180" />
                                </button>
                            @elseif ($execution['download_url'])
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
                @if ($executions->hasPages())
                    <x-table-pagination :from="$executions->firstItem()" :to="$executions->lastItem()"
                        :total="$executions->total()" :current-page="$executions->currentPage()" :last-page="$executions->lastPage()"
                        wire-target="previousPage,nextPage,setPage,perPage"
                        previous-action="previousPage('executionsPage')" next-action="nextPage('executionsPage')" />
                @endif
            </div>
        @endif
    </x-application.settings-section>
</div>
