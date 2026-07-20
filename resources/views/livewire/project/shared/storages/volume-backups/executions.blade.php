    @if ($backup)
        @php
            $executionCount = method_exists($executions, 'total') ? $executions->total() : $executions->count();
            $currentPage = method_exists($executions, 'currentPage') ? $executions->currentPage() : 1;
            $lastPage = method_exists($executions, 'lastPage') ? $executions->lastPage() : 1;
        @endphp
        <div wire:poll.5000ms="$refresh">
            <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                <h2>Executions</h2>
                @if ($executionCount > 0)
                    <div class="flex items-center gap-2">
                        <x-forms.button :disabled="$currentPage === 1" wire:click="previousPage">
                            <svg class="w-4 h-4" viewBox="0 0 24 24">
                                <path fill="none" stroke="currentColor" stroke-linecap="round"
                                    stroke-linejoin="round" stroke-width="2" d="m14 6l-6 6l6 6z" />
                            </svg>
                        </x-forms.button>
                        <span class="px-2 text-sm text-gray-600 dark:text-gray-400">
                            Page {{ $currentPage }} of {{ $lastPage }}
                        </span>
                        <x-forms.button :disabled="$currentPage === $lastPage" wire:click="nextPage">
                            <svg class="w-4 h-4" viewBox="0 0 24 24">
                                <path fill="none" stroke="currentColor" stroke-linecap="round"
                                    stroke-linejoin="round" stroke-width="2" d="m10 18l6-6l-6-6z" />
                            </svg>
                        </x-forms.button>
                    </div>
                @endif
                <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                    <x-forms.button wire:click="cleanupFailed" class="w-full sm:w-auto">
                        Cleanup Failed Backups
                    </x-forms.button>
                    <x-modal-confirmation title="Cleanup Deleted Backup Entries?" isErrorButton
                        submitAction="cleanupDeleted()"
                        :actions="['This permanently deletes backup execution entries whose local and S3 files have already been deleted.', 'This only removes database entries, not backup files.']"
                        confirmationText="cleanup deleted backups"
                        confirmationLabel="Please confirm by typing 'cleanup deleted backups' below"
                        shortConfirmationLabel="Confirmation">
                        <x-slot:trigger>
                            <x-forms.button isError class="w-full sm:w-auto">Cleanup Deleted</x-forms.button>
                        </x-slot:trigger>
                    </x-modal-confirmation>
                </div>
            </div>

            <div class="flex flex-col gap-4 pt-2">
                @forelse ($executions as $execution)
                    <div wire:key="volume-backup-execution-{{ $execution->id }}" @class([
                        'relative flex flex-col border-l-2 transition-colors p-4 bg-white dark:bg-coolgray-100 text-black dark:text-white',
                        'border-blue-500/50 border-dashed' => $execution->status === 'running',
                        'border-error' => $execution->status === 'failed',
                        'border-success' => $execution->status === 'success',
                    ])>
                        @if ($execution->status === 'running')
                            <div class="absolute top-2 right-2"><x-loading /></div>
                        @endif
                        <div class="flex items-center gap-2 mb-2">
                            <span @class([
                                'px-3 py-1 rounded-md text-xs font-medium tracking-wide shadow-xs',
                                'bg-blue-100/80 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300' => $execution->status === 'running',
                                'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200' => $execution->status === 'failed',
                                'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200' => $execution->status === 'success' && $execution->s3_uploaded === false,
                                'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200' => $execution->status === 'success' && $execution->s3_uploaded !== false,
                            ])>
                                @if ($execution->status === 'running')
                                    In Progress
                                @elseif ($execution->status === 'success' && $execution->s3_uploaded === false)
                                    Success (S3 Warning)
                                @else
                                    {{ ucfirst($execution->status) }}
                                @endif
                            </span>
                        </div>
                        <div class="text-sm text-gray-600 dark:text-gray-400">
                            @if ($execution->status === 'running')
                                <span title="Started: {{ $execution->created_at->toDateTimeString() }}">
                                    Running for {{ calculateDuration($execution->created_at, now()) }}
                                </span>
                            @else
                                @php
                                    $finishedAt = $execution->finished_at ?? $execution->updated_at;
                                @endphp
                                <span title="Started: {{ $execution->created_at->toDateTimeString() }}&#10;Ended: {{ $finishedAt->toDateTimeString() }}">
                                    {{ $finishedAt->diffForHumans() }}
                                    ({{ calculateDuration($execution->created_at, $finishedAt) }})
                                    • {{ $finishedAt->format('M j, H:i') }}
                                </span>
                            @endif
                            • {{ $backup->targetType() }}: {{ $backup->targetName() }}
                            @if ($execution->size > 0)
                                • Size: {{ formatBytes($execution->size) }}
                            @endif
                        </div>
                        <div class="text-sm text-gray-600 dark:text-gray-400">
                            Location: {{ $execution->filename ?? 'N/A' }}
                        </div>
                        <div class="flex flex-col gap-2 mt-2 sm:flex-row sm:flex-wrap sm:items-center sm:gap-3">
                            <div class="text-sm text-gray-600 dark:text-gray-400">Backup Availability:</div>
                            <span @class([
                                'px-2 py-1 rounded-sm text-xs font-medium',
                                'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200' => !$execution->local_storage_deleted,
                                'bg-gray-100 text-gray-600 dark:bg-gray-800/50 dark:text-gray-400' => $execution->local_storage_deleted,
                            ])>
                                <span class="flex items-center gap-1">
                                    @if (!$execution->local_storage_deleted)
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                        </svg>
                                    @else
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                                        </svg>
                                    @endif
                                    Local Storage
                                </span>
                            </span>
                            @if ($execution->s3_uploaded !== null)
                                <span @class([
                                    'px-2 py-1 rounded-sm text-xs font-medium',
                                    'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200' => $execution->s3_uploaded === false && !$execution->s3_storage_deleted,
                                    'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200' => $execution->s3_uploaded === true && !$execution->s3_storage_deleted,
                                    'bg-gray-100 text-gray-600 dark:bg-gray-800/50 dark:text-gray-400' => $execution->s3_storage_deleted,
                                ])>
                                    <span class="flex items-center gap-1">
                                        @if ($execution->s3_uploaded === true && !$execution->s3_storage_deleted)
                                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                            </svg>
                                        @else
                                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                                            </svg>
                                        @endif
                                        S3 Storage
                                    </span>
                                </span>
                            @endif
                        </div>
                        @if ($execution->message)
                            <div class="p-2 mt-2 bg-gray-100 rounded-sm dark:bg-coolgray-200">
                                <pre class="text-sm whitespace-pre-wrap">{{ $execution->message }}</pre>
                            </div>
                        @endif
                        @if ($execution->status !== 'running')
                            <div class="grid grid-cols-2 gap-2 mt-4 sm:flex sm:flex-wrap">
                                @if ($execution->status === 'success' && !$execution->local_storage_deleted)
                                    <x-forms.button class="w-full dark:hover:bg-coolgray-400 sm:w-auto"
                                        x-on:click="download_volume_backup_file('{{ $execution->id }}')">
                                        Download
                                    </x-forms.button>
                                @endif
                                @php
                                    $executionCheckboxes = [];
                                    $deleteActions = [];
                                    if (!$execution->local_storage_deleted) {
                                        $deleteActions[] = 'This backup will be permanently deleted from local storage.';
                                    }
                                    if ($execution->s3_uploaded === true && !$execution->s3_storage_deleted) {
                                        $executionCheckboxes[] = ['id' => 'delete_backup_s3', 'label' => 'Delete the selected backup permanently from S3 Storage'];
                                    }
                                    if (empty($deleteActions)) {
                                        $deleteActions[] = 'This backup execution record will be deleted.';
                                    }
                                @endphp
                                <x-modal-confirmation title="Confirm Backup Deletion?" isErrorButton
                                    submitAction="deleteBackup({{ $execution->id }})"
                                    :checkboxes="$executionCheckboxes" :actions="$deleteActions"
                                    confirmationText="{{ $execution->filename }}"
                                    confirmationLabel="Please confirm the execution of the actions by entering the Backup Filename below"
                                    shortConfirmationLabel="Backup Filename">
                                    <x-slot:trigger>
                                        <x-forms.button isError class="w-full sm:w-auto">Delete</x-forms.button>
                                    </x-slot:trigger>
                                </x-modal-confirmation>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="p-4 bg-gray-100 rounded-sm dark:bg-coolgray-100">No executions found.</div>
                @endforelse
            </div>
        </div>
    @endif
