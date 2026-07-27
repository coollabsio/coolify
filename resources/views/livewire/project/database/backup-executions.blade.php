<div wire:init="refreshBackupExecutions">
    @isset($backup)
        <section class="application-settings-section overflow-hidden">
            <div class="application-settings-section-header">
                <div>
                    <h2>Executions</h2>
                    <p>Review generated archives, storage availability, and backup output.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-forms.button wire:click="cleanupFailed">Clean failed backups</x-forms.button>
                    <x-modal-confirmation title="Cleanup Deleted Backup Entries?" isErrorButton
                        submitAction="cleanupDeleted()" :actions="[
                            'Permanently delete execution records already removed from local storage.',
                            'Actual backup files are not changed.',
                        ]"
                        confirmationText="cleanup deleted backups"
                        confirmationLabel="Type cleanup deleted backups to confirm."
                        shortConfirmationLabel="Confirmation">
                        <x-slot:trigger>
                            <x-forms.button isError>Clean deleted entries</x-forms.button>
                        </x-slot:trigger>
                    </x-modal-confirmation>
                </div>
            </div>

            <div @if (! $skip) wire:poll.5000ms="refreshBackupExecutions" @endif
                class="application-settings-section-body p-0!">
                <div class="data-table">
                    <div class="data-table-header backup-executions-table-grid">
                        <span>Status</span>
                        <span>Database</span>
                        <span>Finished</span>
                        <span>Duration</span>
                        <span>Size</span>
                        <span>Availability</span>
                        <span class="text-right">Actions</span>
                    </div>
                    @forelse ($executions as $execution)
                        @php
                            $executionStatus = data_get($execution, 'status');
                            [$executionStatusLabel, $executionStatusType] = match ($executionStatus) {
                                'success' => data_get($execution, 's3_uploaded') === false
                                    ? ['S3 warning', 'warning']
                                    : ['Success', 'success'],
                                'running' => ['In progress', 'warning'],
                                'failed' => ['Failed', 'error'],
                                default => [str($executionStatus)->headline(), 'neutral'],
                            };
                            $executionCheckboxes = [];
                            $deleteActions = [];

                            if (! data_get($execution, 'local_storage_deleted', false)) {
                                $deleteActions[] = 'This backup will be permanently deleted from local storage.';
                            }

                            if (data_get($execution, 's3_uploaded') === true
                                && ! data_get($execution, 's3_storage_deleted', false)) {
                                $executionCheckboxes[] = [
                                    'id' => 'delete_backup_s3',
                                    'label' => 'Delete the selected backup permanently from S3 Storage',
                                ];
                            }

                            if (empty($deleteActions)) {
                                $deleteActions[] = 'This backup execution record will be deleted.';
                            }
                        @endphp
                        <div wire:key="{{ data_get($execution, 'id') }}"
                            class="border-b border-neutral-200 last:border-b-0 dark:border-white/[0.06]">
                            <div class="data-table-row backup-executions-table-grid">
                                <div class="flex items-center gap-2">
                                    <x-status-badge :status="$executionStatusLabel"
                                        :type="$executionStatusType" />
                                    @if ($executionStatus === 'running')
                                        <x-loading />
                                    @endif
                                </div>
                                <div class="truncate text-[12px] font-medium text-black dark:text-fg">
                                    {{ data_get($execution, 'database_name', 'N/A') }}
                                </div>
                                <div class="text-[11px] text-neutral-600 dark:text-fg-dim">
                                    @if ($executionStatus === 'running')
                                        Running now
                                    @else
                                        {{ \Carbon\Carbon::parse(data_get($execution, 'finished_at'))->diffForHumans() }}
                                    @endif
                                </div>
                                <div class="text-[11px] text-neutral-600 dark:text-fg-dim">
                                    {{ calculateDuration(
                                        data_get($execution, 'created_at'),
                                        $executionStatus === 'running' ? now() : data_get($execution, 'finished_at'),
                                    ) }}
                                </div>
                                <div class="text-[11px] text-neutral-600 dark:text-fg-dim">
                                    {{ data_get($execution, 'size') ? formatBytes(data_get($execution, 'size')) : '—' }}
                                </div>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <x-status-badge label="Local"
                                        :status="data_get($execution, 'local_storage_deleted', false) ? 'Deleted' : 'Available'"
                                        :type="data_get($execution, 'local_storage_deleted', false) ? 'neutral' : 'success'" />
                                    @if (data_get($execution, 's3_uploaded') !== null)
                                        <x-status-badge label="S3"
                                            :status="data_get($execution, 's3_storage_deleted', false)
                                                ? 'Deleted'
                                                : (data_get($execution, 's3_uploaded') ? 'Available' : 'Failed')"
                                            :type="data_get($execution, 's3_storage_deleted', false)
                                                ? 'neutral'
                                                : (data_get($execution, 's3_uploaded') ? 'success' : 'error')" />
                                    @endif
                                </div>
                                <div class="flex items-center justify-end gap-1">
                                    @if ($executionStatus === 'success')
                                        <button type="button" class="button"
                                            x-on:click="download_file('{{ data_get($execution, 'id') }}')">
                                            Download
                                        </button>
                                    @endif
                                    <x-modal-confirmation title="Confirm Backup Deletion?" isErrorButton
                                        submitAction="deleteBackup({{ data_get($execution, 'id') }})"
                                        :checkboxes="$executionCheckboxes" :actions="$deleteActions"
                                        confirmationText="{{ data_get($execution, 'filename') }}"
                                        confirmationLabel="Enter the backup filename to confirm."
                                        shortConfirmationLabel="Backup Filename">
                                        <x-slot:trigger>
                                            <x-forms.button isError>Delete</x-forms.button>
                                        </x-slot:trigger>
                                    </x-modal-confirmation>
                                </div>
                            </div>
                            @if (data_get($execution, 'message'))
                                <div class="border-t border-neutral-200 bg-neutral-50 px-4 py-3 dark:border-white/[0.06] dark:bg-white/[0.02]">
                                    <pre
                                        class="max-h-48 overflow-auto whitespace-pre-wrap rounded-lg bg-neutral-100 p-3 font-mono text-xs leading-5 text-neutral-700 dark:bg-black/20 dark:text-fg-dim">{{ data_get($execution, 'message') }}</pre>
                                </div>
                            @endif
                        </div>
                    @empty
                        <x-empty size="sm" title="No backup executions"
                            description="Execution history appears here after the schedule runs.">
                            <x-slot:icon>
                                <x-reicon name="terminal" class="size-5" />
                            </x-slot:icon>
                        </x-empty>
                    @endforelse
                </div>

                @if ($executions_count > 0)
                    <div
                        class="flex items-center justify-between border-t border-neutral-200 px-4 py-3 text-sm text-neutral-500 dark:border-white/[0.06] dark:text-fg-dim">
                        <span>{{ $executions_count }} execution{{ $executions_count === 1 ? '' : 's' }}</span>
                        <div class="flex items-center gap-2">
                            <span>Page {{ $currentPage }} of {{ ceil($executions_count / $defaultTake) }}</span>
                            <button type="button" class="icon-button" @disabled(! $showPrev)
                                wire:click="previousPage('{{ $defaultTake }}')" aria-label="Previous page">
                                <x-reicon name="arrow-right" class="size-3.5 rotate-180" />
                            </button>
                            <button type="button" class="icon-button" @disabled(! $showNext)
                                wire:click="nextPage('{{ $defaultTake }}')" aria-label="Next page">
                                <x-reicon name="arrow-right" class="size-3.5" />
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        </section>
    @endisset
</div>

@script
    <script>
        window.download_file = function(executionId) {
            window.open('/download/backup/' + executionId, '_blank');
        }
    </script>
@endscript
