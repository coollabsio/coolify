<div x-on:open-backup-download.window="window.location.href = $event.detail.url">
    @if (count($pendingDownloads) > 0)
        <div wire:poll.3s="checkDownloads" class="hidden"></div>
    @endif
    @if ($executions->onFirstPage())
        <div wire:poll.5000ms class="hidden"></div>
    @endif
    @php
        $server = $database->destination?->server;
    @endphp
    @if ($selectedExecution)
        <x-modal-input title="Backup execution" wireOpen="executionModalOpen" :wireIgnore="false" isLarge>
            <x-slot:content><span></span></x-slot:content>
            <div class="flex flex-col gap-5">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <div><p class="text-xs text-neutral-500 dark:text-fg-dim">Schedule</p><p class="mt-1 text-sm font-medium">{{ $selectedExecution['schedule'] }}</p></div>
                    <div><p class="text-xs text-neutral-500 dark:text-fg-dim">Status</p><p class="mt-1 text-sm font-medium">{{ str($selectedExecution['status'])->headline() }}</p></div>
                    <div><p class="text-xs text-neutral-500 dark:text-fg-dim">Started</p><p class="mt-1 text-sm font-medium" title="{{ $selectedExecution['started_at']->diffForHumans() }}">{{ formatDateInServerTimezone($selectedExecution['started_at'], $server) }}</p></div>
                    <div><p class="text-xs text-neutral-500 dark:text-fg-dim">{{ $selectedExecution['status'] === 'running' ? 'Running for' : 'Ended' }}</p><p class="mt-1 text-sm font-medium">@if ($selectedExecution['status'] === 'running'){{ calculateDuration($selectedExecution['started_at'], now()) }}@else{{ formatDateInServerTimezone($selectedExecution['finished_at'], $server) }}<span class="block text-xs font-normal text-neutral-500 dark:text-fg-dim">Took {{ calculateDuration($selectedExecution['started_at'], $selectedExecution['finished_at']) }}</span>@endif</p></div>
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
        helper="Review backup runs across every schedule of this database." flush>
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
                <div class="flex min-w-[48rem] flex-col">
                    <div class="data-table-header database-backup-table-grid">
                        <x-table.sort-header label="Schedule" asc="schedule_asc" desc="schedule_desc" action="sortExecutions" :current="$sortBy" />
                        <x-table.sort-header label="Status" asc="status_asc" desc="status_desc" action="sortExecutions" :current="$sortBy" />
                        <span>S3</span>
                        <x-table.sort-header label="Time" asc="started_asc" desc="started_desc" first="desc" action="sortExecutions" :current="$sortBy" />
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
                        <div wire:key="database-backup-execution-{{ $execution['id'] }}"
                            wire:click="openExecution('{{ $execution['uuid'] }}')"
                            wire:keydown.enter="openExecution('{{ $execution['uuid'] }}')" role="button" tabindex="0"
                            class="data-table-row database-backup-table-grid cursor-pointer text-left text-[13px] text-neutral-700 dark:text-fg-dim">
                            <span class="truncate font-medium text-neutral-950 dark:text-fg">{{ $execution['schedule'] }}</span>
                            <span><x-status-badge :status="str($execution['status'])->headline()" :type="$statusType" /></span>
                            <span class="min-w-0">
                                <x-backup-s3-storage :enabled="$execution['s3_names']->isNotEmpty()" :names="$execution['s3_names']" :tooltip="$execution['s3_tooltip']" />
                            </span>
                            <span>
                                <span class="block text-[11px] text-neutral-500 dark:text-fg-faint" tabindex="0"
                                    title="Started: {{ formatDateInServerTimezone($execution['started_at'], $server) }}@if ($execution['status'] !== 'running')&#10;Ended: {{ formatDateInServerTimezone($execution['finished_at'], $server) }}@endif">
                                    @if ($execution['status'] === 'running')
                                        Running for {{ calculateDuration($execution['started_at'], now()) }}
                                    @else
                                        <span class="block text-[13px] text-black dark:text-fg">{{ formatDateInServerTimezone($execution['finished_at'], $server) }}</span>
                                        {{ $execution['finished_at']->diffForHumans() }} · {{ calculateDuration($execution['started_at'], $execution['finished_at']) }}
                                    @endif
                                </span>
                            </span>
                            <span>{{ $execution['size'] ? formatBytes($execution['size']) : '-' }}</span>
                            <span class="flex justify-end">
                                @can('manageBackups', $database)
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
                                @endcan
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
