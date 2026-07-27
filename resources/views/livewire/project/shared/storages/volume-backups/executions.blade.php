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
                <div class="data-table w-full">
                    <div
                        class="grid grid-cols-[7.5rem_minmax(0,1fr)_7rem_9rem_minmax(9rem,.7fr)] border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-faint">
                        <span>Status</span>
                        <span>Archive</span>
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
                            class="grid min-h-16 grid-cols-[7.5rem_minmax(0,1fr)_7rem_9rem_minmax(9rem,.7fr)] items-center border-b border-neutral-200 px-4 py-2.5 text-[12px] last:border-b-0 dark:border-white/[0.07]">
                            <span>
                                <x-status-badge :status="$statusLabel" :type="$statusType" />
                            </span>

                            <span class="min-w-0">
                                <span class="block truncate font-medium text-black dark:text-fg"
                                    title="{{ $execution->filename ?? 'No archive name' }}">
                                    {{ $execution->filename ?? 'No archive name' }}
                                </span>
                                <span class="mt-0.5 block truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                    @if ($execution->status === 'running')
                                        Running for {{ calculateDuration($execution->created_at, now()) }}
                                    @else
                                        {{ $finishedAt->diffForHumans() }} ·
                                        {{ calculateDuration($execution->created_at, $finishedAt) }}
                                    @endif
                                </span>
                            </span>

                            <span class="tabular-nums text-neutral-500 dark:text-fg-dim">
                                {{ $execution->size > 0 ? formatBytes($execution->size) : '—' }}
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

                            <span class="flex items-center justify-end gap-2">
                                @if ($execution->status === 'success' && ! $execution->local_storage_deleted)
                                    <x-forms.button
                                        x-on:click="download_volume_backup_file('{{ $execution->id }}')">
                                        Download
                                    </x-forms.button>
                                @endif
                                @if ($execution->status !== 'running')
                                    <x-modal-confirmation title="Confirm Backup Deletion?" isErrorButton
                                        submitAction="deleteBackup({{ $execution->id }})"
                                        :checkboxes="$executionCheckboxes" :actions="$deleteActions"
                                        confirmationText="{{ $execution->filename }}"
                                        confirmationLabel="Please confirm the execution of the actions by entering the Backup Filename below"
                                        shortConfirmationLabel="Backup Filename">
                                        <x-slot:trigger>
                                            <x-forms.button isError>Delete</x-forms.button>
                                        </x-slot:trigger>
                                    </x-modal-confirmation>
                                @endif
                            </span>

                            @if ($execution->message)
                                <pre
                                    class="col-span-5 mt-2 max-h-32 overflow-auto rounded-lg border border-neutral-200 bg-neutral-50 p-2 text-[11px] whitespace-pre-wrap text-neutral-600 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">{{ $execution->message }}</pre>
                            @endif
                        </div>
                    @endforeach

                    <footer
                        class="flex min-h-11 items-center justify-between border-t border-neutral-200 px-4 text-[11px] text-neutral-500 dark:border-white/[0.08] dark:text-fg-faint">
                        <span>{{ $executionCount }} {{ Str::plural('execution', $executionCount) }}</span>
                        <div class="flex items-center gap-1">
                            <button type="button" wire:click="previousPage" @disabled($currentPage === 1)
                                class="flex size-7 items-center justify-center rounded-md border border-neutral-200 text-neutral-500 transition-colors hover:bg-neutral-100 disabled:pointer-events-none disabled:opacity-35 dark:border-white/[0.08] dark:text-fg-dim dark:hover:bg-white/[0.06]"
                                aria-label="Previous page">
                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none">
                                    <path d="m15 5-7 7 7 7" stroke="currentColor" stroke-width="1.7"
                                        stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <span class="min-w-14 text-center tabular-nums">{{ $currentPage }} / {{ $lastPage }}</span>
                            <button type="button" wire:click="nextPage" @disabled($currentPage === $lastPage)
                                class="flex size-7 items-center justify-center rounded-md border border-neutral-200 text-neutral-500 transition-colors hover:bg-neutral-100 disabled:pointer-events-none disabled:opacity-35 dark:border-white/[0.08] dark:text-fg-dim dark:hover:bg-white/[0.06]"
                                aria-label="Next page">
                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none">
                                    <path d="m9 5 7 7-7 7" stroke="currentColor" stroke-width="1.7"
                                        stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                        </div>
                    </footer>
                </div>
            @else
                <x-empty size="sm" title="No executions"
                    description="Run the backup schedule to create its first execution.">
                    <x-slot:icon>
                        <x-reicon name="storages" class="size-6" />
                    </x-slot:icon>
                </x-empty>
            @endif
        </x-application.settings-section>
    </div>
@endif
