@if ($backup)
    @php
        $executionCount = method_exists($executions, 'total') ? $executions->total() : $executions->count();
        $currentPage = method_exists($executions, 'currentPage') ? $executions->currentPage() : 1;
        $lastPage = method_exists($executions, 'lastPage') ? $executions->lastPage() : 1;
    @endphp

    <div wire:poll.5000ms="$refresh">
        <x-application.settings-section title="Executions"
            description="Review generated archives, availability, and cleanup status." flush>
            <x-slot:actions>
                <x-forms.button wire:click="cleanupFailed">Cleanup failed</x-forms.button>
                <x-modal-confirmation title="Cleanup Deleted Backup Entries?" isErrorButton
                    submitAction="cleanupDeleted()"
                    :actions="['This permanently deletes backup execution entries whose local and S3 files have already been deleted.', 'This only removes database entries, not backup files.']"
                    confirmationText="cleanup deleted backups"
                    confirmationLabel="Please confirm by typing 'cleanup deleted backups' below"
                    shortConfirmationLabel="Confirmation">
                    <x-slot:trigger>
                        <x-forms.button isError>Cleanup deleted</x-forms.button>
                    </x-slot:trigger>
                </x-modal-confirmation>
            </x-slot:actions>

            @if ($executionCount > 0)
                <div class="data-table w-full overflow-x-auto">
                    <div
                        class="data-table-header volume-backup-executions-grid border-b border-neutral-200 bg-neutral-50 dark:border-white/[0.08] dark:bg-white/[0.025]">
                        <span>Status</span>
                        <span>Archive</span>
                        <span>Time</span>
                        <span>Size</span>
                        <span>Availability</span>
                        <span class="text-right">Actions</span>
                    </div>

                    @foreach ($executions as $execution)
                        @php
                            $finishedAt = $execution->finished_at ?? $execution->updated_at;
                            $statusLabel = match (true) {
                                $execution->status === 'running' => 'In progress',
                                $execution->status === 'success' && $execution->s3_uploaded === false => 'S3 warning',
                                default => str($execution->status)->headline()->toString(),
                            };
                            $statusType = match ($execution->status) {
                                'running' => 'warning',
                                'success' => $execution->s3_uploaded === false ? 'warning' : 'success',
                                'failed' => 'error',
                                default => 'neutral',
                            };
                            $executionCheckboxes = [];
                            $deleteActions = [];
                            if (! $execution->local_storage_deleted) {
                                $deleteActions[] = 'This backup will be permanently deleted from local storage.';
                            }
                            if ($execution->s3_uploaded === true && ! $execution->s3_storage_deleted) {
                                $executionCheckboxes[] = [
                                    'id' => 'delete_backup_s3',
                                    'label' => 'Delete the selected backup permanently from S3 Storage',
                                ];
                            }
                            if (empty($deleteActions)) {
                                $deleteActions[] = 'This backup execution record will be deleted.';
                            }
                        @endphp

                        <div wire:key="volume-backup-execution-{{ $execution->id }}"
                            class="data-table-row volume-backup-executions-grid min-h-16 items-center gap-x-3 border-b border-neutral-200 text-[12px] last:border-b-0 dark:border-white/[0.07]">
                            <span>
                                <x-status-badge :status="$statusLabel" :type="$statusType" />
                            </span>

                            <span class="min-w-0">
                                <x-forms.copy-button :text="$execution->filename ?? 'No archive name'" />
                            </span>

                            <span class="text-[11px] text-neutral-500 dark:text-fg-faint">
                                @if ($execution->status === 'running')
                                    Running for {{ calculateDuration($execution->created_at, now()) }}
                                @else
                                    {{ $finishedAt->diffForHumans() }}<br>
                                    {{ calculateDuration($execution->created_at, $finishedAt) }}
                                @endif
                            </span>

                            <span class="tabular-nums text-neutral-500 dark:text-fg-dim">
                                {{ $execution->size > 0 ? formatBytes($execution->size) : '-' }}
                            </span>

                            <span class="flex flex-wrap gap-1">
                                <x-status-badge :status="$execution->local_storage_deleted ? 'Local deleted' : 'Local'"
                                    :type="$execution->local_storage_deleted ? 'neutral' : 'success'" />
                                @if ($execution->s3_uploaded !== null)
                                    <x-status-badge
                                        :status="$execution->s3_storage_deleted
                                            ? 'S3 deleted'
                                            : ($execution->s3_uploaded ? 'S3' : 'S3 failed')"
                                        :type="$execution->s3_storage_deleted
                                            ? 'neutral'
                                            : ($execution->s3_uploaded ? 'success' : 'error')" />
                                @endif
                            </span>

                            <span class="flex items-center justify-end gap-1">
                                @if ($execution->status === 'success' && ! $execution->local_storage_deleted)
                                    <button type="button" class="icon-button shrink-0"
                                        x-on:click="download_volume_backup_file('{{ $execution->id }}')"
                                        title="Download backup" aria-label="Download backup">
                                        <x-reicon name="upload" class="size-3.5 rotate-180" />
                                    </button>
                                @endif
                                @if ($execution->status !== 'running')
                                    <x-modal-confirmation title="Confirm Backup Deletion?" isErrorButton
                                        submitAction="deleteBackup({{ $execution->id }})"
                                        :checkboxes="$executionCheckboxes" :actions="$deleteActions"
                                        confirmationText="{{ $execution->filename }}"
                                        confirmationLabel="Please confirm the execution of the actions by entering the Backup Filename below"
                                        shortConfirmationLabel="Backup Filename">
                                        <x-slot:trigger>
                                            <button type="button"
                                                class="icon-button shrink-0 text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300"
                                                title="Delete backup" aria-label="Delete backup">
                                                <x-reicon name="trash" class="size-3.5" />
                                            </button>
                                        </x-slot:trigger>
                                    </x-modal-confirmation>
                                @endif
                            </span>

                            @if ($execution->message)
                                <pre
                                    class="volume-backup-execution-message col-span-6 mt-2 max-h-32 overflow-auto rounded-lg border border-neutral-200 bg-neutral-50 p-2 text-[11px] whitespace-pre-wrap text-neutral-600 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">{{ $execution->message }}</pre>
                            @endif
                        </div>
                    @endforeach
                    <x-table-pagination :from="$executions->firstItem() ?? 0" :to="$executions->lastItem() ?? 0"
                        :total="$executionCount" :current-page="$currentPage" :last-page="$lastPage"
                        wire-target="previousPage,nextPage" previous-action="previousPage" next-action="nextPage">
                        <x-slot:pageSize>
                            <x-page-size-select model="perPage" livewire
                                storage-key="coolify.page-size.volume-backup-executions" />
                        </x-slot:pageSize>
                    </x-table-pagination>
                </div>
            @else
                <x-empty size="sm" title="No executions"
                    description="Run the backup schedule to create its first execution."
                    icon-name="storages" />
            @endif
        </x-application.settings-section>
    </div>
@endif
