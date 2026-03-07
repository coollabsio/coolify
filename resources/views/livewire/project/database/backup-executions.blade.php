<div wire:init='refreshBackupExecutions'>
    @isset($backup)
        <div class="flex items-center gap-2">
            <h3 class="py-4">Executions <span class="text-xs">({{ $executions_count }})</span></h3>
            @if ($executions_count > 0)
                <div class="flex items-center gap-2">
                    <x-forms.button disabled="{{ !$showPrev }}" wire:click="previousPage('{{ $defaultTake }}')">
                        <svg class="w-4 h-4" viewBox="0 0 24 24">
                            <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="2" d="m14 6l-6 6l6 6z" />
                        </svg>
                    </x-forms.button>
                    <span class="text-sm text-gray-600 dark:text-gray-400 px-2">
                        Page {{ $currentPage }} of {{ ceil($executions_count / $defaultTake) }}
                    </span>
                    <x-forms.button disabled="{{ !$showNext }}" wire:click="nextPage('{{ $defaultTake }}')">
                        <svg class="w-4 h-4" viewBox="0 0 24 24">
                            <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="2" d="m10 18l6-6l-6-6z" />
                        </svg>
                    </x-forms.button>
                </div>
            @endif
            <x-forms.button wire:click='cleanupFailed'>Cleanup Failed Backups</x-forms.button>
            <x-modal-confirmation title="Cleanup Deleted Backup Entries?" buttonTitle="Cleanup Deleted" isErrorButton
                submitAction="cleanupDeleted()" 
                :actions="['This will permanently delete all backup execution entries that are marked as deleted from local storage.', 'This only removes database entries, not actual backup files.']" 
                confirmationText="cleanup deleted backups"
                confirmationLabel="Please confirm by typing 'cleanup deleted backups' below"
                shortConfirmationLabel="Confirmation" />
        </div>
        <div @if (!$skip) wire:poll.5000ms="refreshBackupExecutions" @endif
            class="flex flex-col gap-4">
            @forelse($executions as $execution)
                <div wire:key="{{ data_get($execution, 'id') }}" @class([
                    'flex flex-col border-l-2 transition-colors p-4 bg-white dark:bg-coolgray-100 text-black dark:text-white',
                    'border-blue-500/50 border-dashed' =>
                        data_get($execution, 'status') === 'running',
                    'border-error' => data_get($execution, 'status') === 'failed',
                    'border-success' => data_get($execution, 'status') === 'success',
                ])>
                    @if (data_get($execution, 'status') === 'running')
                        <div class="absolute top-2 right-2">
                            <x-loading />
                        </div>
                    @endif
                    <div class="flex items-center gap-2 mb-2">
                        <span @class([
                            'px-3 py-1 rounded-md text-xs font-medium tracking-wide shadow-xs',
                            'bg-blue-100/80 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300 dark:shadow-blue-900/5' =>
                                data_get($execution, 'status') === 'running',
                            'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200 dark:shadow-red-900/5' =>
                                data_get($execution, 'status') === 'failed',
                            'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200 dark:shadow-amber-900/5' =>
                                data_get($execution, 'status') === 'success' && data_get($execution, 's3_uploaded') === false,
                            'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200 dark:shadow-green-900/5' =>
                                data_get($execution, 'status') === 'success' && data_get($execution, 's3_uploaded') !== false,
                        ])>
                            @php
                                $statusText = match (data_get($execution, 'status')) {
                                    'success' => data_get($execution, 's3_uploaded') === false ? 'Success (S3 Warning)' : 'Success',
                                    'running' => 'In Progress',
                                    'failed' => 'Failed',
                                    default => ucfirst(data_get($execution, 'status')),
                                };
                            @endphp
                            {{ $statusText }}
                        </span>
                        {{-- Show pgBackRest badge if this is a pgBackRest backup --}}
                        @if (data_get($execution, 'engine') === 'pgbackrest')
                            <span class="px-2 py-1 rounded-md text-xs font-medium bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-200">
                                pgBackRest
                                @if (data_get($execution, 'pgbackrest_backup_type'))
                                    ({{ ucfirst(data_get($execution, 'pgbackrest_backup_type')) }})
                                @endif
                            </span>
                        @endif
                    </div>
                    <div class="text-gray-600 dark:text-gray-400 text-sm">
                        @if (data_get($execution, 'status') === 'running')
                            <span title="Started: {{ formatDateInServerTimezone(data_get($execution, 'created_at'), $this->server()) }}">
                                Running for {{ calculateDuration(data_get($execution, 'created_at'), now()) }}
                            </span>
                        @else
                            <span title="Started: {{ formatDateInServerTimezone(data_get($execution, 'created_at'), $this->server()) }}&#10;Ended: {{ formatDateInServerTimezone(data_get($execution, 'finished_at'), $this->server()) }}">
                                {{ \Carbon\Carbon::parse(data_get($execution, 'finished_at'))->diffForHumans() }}
                                ({{ calculateDuration(data_get($execution, 'created_at'), data_get($execution, 'finished_at')) }})
                                • {{ \Carbon\Carbon::parse(data_get($execution, 'finished_at'))->format('M j, H:i') }}
                            </span>
                        @endif
                        • Database: {{ data_get($execution, 'database_name', 'N/A') }}
                        @if(data_get($execution, 'size'))
                            • Size: {{ formatBytes(data_get($execution, 'size')) }}
                        @elseif(data_get($execution, 'pgbackrest_repo_size'))
                            • Repo Size: {{ formatBytes(data_get($execution, 'pgbackrest_repo_size')) }}
                        @endif
                    </div>
                    <div class="text-gray-600 dark:text-gray-400 text-sm">
                        @if (data_get($execution, 'engine') === 'pgbackrest' && data_get($execution, 'pgbackrest_label'))
                            Label: <code class="px-1 py-0.5 bg-gray-200 dark:bg-coolgray-300 rounded text-xs">{{ data_get($execution, 'pgbackrest_label') }}</code>
                        @else
                            Location: {{ data_get($execution, 'filename', 'N/A') }}
                        @endif
                    </div>
                    <div class="flex items-center gap-3 mt-2">
                        <div class="text-gray-600 dark:text-gray-400 text-sm">
                            Backup Availability:
                        </div>
                        @if (data_get($execution, 'engine') === 'pgbackrest')
                            {{-- For pgBackRest, show repository status instead of local/S3 --}}
                            <span class="px-2 py-1 rounded-sm text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200">
                                <span class="flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd"
                                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                            clip-rule="evenodd"></path>
                                    </svg>
                                    pgBackRest Repository
                                </span>
                            </span>
                        @else
                            <span @class([
                                'px-2 py-1 rounded-sm text-xs font-medium',
                                'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200' => !data_get(
                                    $execution,
                                    'local_storage_deleted',
                                    false),
                                'bg-gray-100 text-gray-600 dark:bg-gray-800/50 dark:text-gray-400' => data_get(
                                    $execution,
                                    'local_storage_deleted',
                                    false),
                            ])>
                                <span class="flex items-center gap-1">
                                    @if (!data_get($execution, 'local_storage_deleted', false))
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"
                                            xmlns="http://www.w3.org/2000/svg">
                                            <path fill-rule="evenodd"
                                                d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                                clip-rule="evenodd"></path>
                                        </svg>
                                    @else
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"
                                            xmlns="http://www.w3.org/2000/svg">
                                            <path fill-rule="evenodd"
                                                d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                                                clip-rule="evenodd"></path>
                                        </svg>
                                    @endif
                                    Local Storage
                                </span>
                            </span>
                            @if (data_get($execution, 's3_uploaded') !== null)
                                <span @class([
                                    'px-2 py-1 rounded-sm text-xs font-medium',
                                    'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200' => data_get($execution, 's3_uploaded') === false && !data_get($execution, 's3_storage_deleted', false),
                                    'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200' => data_get($execution, 's3_uploaded') === true && !data_get($execution, 's3_storage_deleted', false),
                                    'bg-gray-100 text-gray-600 dark:bg-gray-800/50 dark:text-gray-400' => data_get($execution, 's3_storage_deleted', false),
                                ])>
                                    <span class="flex items-center gap-1">
                                        @if (data_get($execution, 's3_uploaded') === true && !data_get($execution, 's3_storage_deleted', false))
                                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"
                                                xmlns="http://www.w3.org/2000/svg">
                                                <path fill-rule="evenodd"
                                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                                    clip-rule="evenodd"></path>
                                            </svg>
                                        @else
                                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"
                                                xmlns="http://www.w3.org/2000/svg">
                                                <path fill-rule="evenodd"
                                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                                                    clip-rule="evenodd"></path>
                                            </svg>
                                        @endif
                                        S3 Storage
                                    </span>
                                </span>
                            @endif
                        @endif
                    </div>
                    @if (data_get($execution, 'message'))
                        <div class="mt-2 p-2 bg-gray-100 dark:bg-coolgray-200 rounded-sm">
                            <pre class="whitespace-pre-wrap text-sm">{{ data_get($execution, 'message') }}</pre>
                        </div>
                    @endif
                    <div class="flex gap-2 mt-4">
                        @if (data_get($execution, 'status') === 'success')
                            @if (data_get($execution, 'engine') !== 'pgbackrest')
                                <x-forms.button class="dark:hover:bg-coolgray-400"
                                    x-on:click="download_file('{{ data_get($execution, 'id') }}')">Download</x-forms.button>
                            @endif
                            {{-- Show Restore button for successful pgBackRest backups --}}
                            @if (data_get($execution, 'engine') === 'pgbackrest' && data_get($execution, 'pgbackrest_label'))
                                @can('manage', $database)
                                    <x-modal-confirmation 
                                        title="Restore Database from pgBackRest Backup?" 
                                        buttonTitle="Restore" 
                                        isErrorButton
                                        submitAction="startRestore({{ data_get($execution, 'id') }})" 
                                        :actions="[
                                            'This will stop the PostgreSQL database and restore it from the selected backup.',
                                            'All data written after this backup was taken will be permanently lost.',
                                            'The database will be temporarily unavailable during the restore process.',
                                            'After restore, the database will automatically restart.',
                                        ]"
                                        confirmationText="restore"
                                        confirmationLabel="Please type 'restore' to confirm this action"
                                        shortConfirmationLabel="Confirmation" />
                                @endcan
                            @endif
                        @endif
                        @php
                            $executionCheckboxes = [];
                            $deleteActions = [];

                            if (data_get($execution, 'engine') === 'pgbackrest') {
                                $deleteActions[] = 'This will remove the backup entry from this list.';
                                $deleteActions[] = 'Note: The actual backup data in pgBackRest is managed by retention policies.';
                            } else {
                                if (!data_get($execution, 'local_storage_deleted', false)) {
                                    $deleteActions[] = 'This backup will be permanently deleted from local storage.';
                                }

                                if (data_get($execution, 's3_uploaded') === true && !data_get($execution, 's3_storage_deleted', false)) {
                                    $executionCheckboxes[] = ['id' => 'delete_backup_s3', 'label' => 'Delete the selected backup permanently from S3 Storage'];
                                }

                                if (empty($deleteActions)) {
                                    $deleteActions[] = 'This backup execution record will be deleted.';
                                }
                            }
                        @endphp
                        <x-modal-confirmation title="Confirm Backup Deletion?" buttonTitle="Delete" isErrorButton
                            submitAction="deleteBackup({{ data_get($execution, 'id') }})" :checkboxes="$executionCheckboxes"
                            :actions="$deleteActions" confirmationText="{{ data_get($execution, 'pgbackrest_label') ?: data_get($execution, 'filename') }}"
                            confirmationLabel="Please confirm the execution of the actions by entering the Backup {{ data_get($execution, 'engine') === 'pgbackrest' ? 'Label' : 'Filename' }} below"
                            shortConfirmationLabel="Backup {{ data_get($execution, 'engine') === 'pgbackrest' ? 'Label' : 'Filename' }}" />
                    </div>
                </div>
            @empty
                <div class="p-4 bg-gray-100 dark:bg-coolgray-100 rounded-sm">No executions found.</div>
            @endforelse
        </div>
        {{-- Restore Confirmation Modal --}}
        @can('manage', $database)
        @if ($showRestoreModal && $restoreExecutionId)
            @php
                $restoreExecution = $executions->firstWhere('id', $restoreExecutionId);
            @endphp
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" wire:key="restore-modal">
                <div class="bg-white dark:bg-coolgray-100 rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="flex-shrink-0 w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center">
                            <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                            </svg>
                        </div>
                        <h3 class="text-lg font-semibold">Restore Database from pgBackRest Backup?</h3>
                    </div>

                    <div class="space-y-3 mb-6">
                        <div class="p-3 bg-gray-100 dark:bg-coolgray-200 rounded">
                            <p class="text-sm font-medium mb-1">Backup Label:</p>
                            <code class="text-sm">{{ data_get($restoreExecution, 'pgbackrest_label') }}</code>
                        </div>

                        <div class="space-y-2 text-sm text-gray-600 dark:text-gray-400">
                            <p class="flex items-start gap-2">
                                <svg class="w-4 h-4 text-red-500 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                                </svg>
                                This will stop the PostgreSQL database and restore it from the selected backup.
                            </p>
                            <p class="flex items-start gap-2">
                                <svg class="w-4 h-4 text-red-500 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                                </svg>
                                All data written after this backup was taken will be permanently lost.
                            </p>
                            <p class="flex items-start gap-2">
                                <svg class="w-4 h-4 text-blue-500 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                </svg>
                                The database will be temporarily unavailable during the restore process.
                            </p>
                            <p class="flex items-start gap-2">
                                <svg class="w-4 h-4 text-green-500 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                                After restore, the database will automatically restart.
                            </p>
                        </div>
                    </div>

                    <div x-data="{ confirmValue: '' }">
                        <div class="mb-4">
                            <label class="block text-sm font-medium mb-2">Type <code class="px-1 py-0.5 bg-gray-200 dark:bg-coolgray-300 rounded">restore</code> to confirm:</label>
                            <input type="text" 
                                class="w-full px-3 py-2 border border-gray-300 dark:border-coolgray-400 rounded-md bg-white dark:bg-coolgray-200 text-black dark:text-white"
                                placeholder="restore"
                                x-model="confirmValue" />
                        </div>

                        <div class="flex justify-end gap-3">
                            <x-forms.button wire:click="cancelRestore">Cancel</x-forms.button>
                            <x-forms.button isError 
                                x-on:click="if (confirmValue === 'restore') { $wire.startRestore({{ $restoreExecutionId }}) }"
                                x-bind:disabled="confirmValue !== 'restore'">
                                Restore Database
                            </x-forms.button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
        @endcan

        {{-- Restore Progress Modal --}}
        @if ($showRestoreProgressModal && $currentRestore)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" 
                wire:key="restore-progress-modal"
                @if (!$currentRestore->isFinished()) wire:poll.2000ms="pollRestoreStatus" @endif>
                <div class="bg-white dark:bg-coolgray-100 rounded-lg shadow-xl max-w-lg w-full mx-4 p-6">
                    {{-- Header with status icon --}}
                    <div class="flex items-center gap-3 mb-4">
                        @if ($currentRestore->isRunning() || $currentRestore->isPending())
                            <div class="flex-shrink-0 w-10 h-10 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                                <svg class="w-5 h-5 text-blue-600 dark:text-blue-400 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </div>
                        @elseif ($currentRestore->isSuccess())
                            <div class="flex-shrink-0 w-10 h-10 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                                <svg class="w-5 h-5 text-green-600 dark:text-green-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                        @elseif ($currentRestore->isFailed())
                            <div class="flex-shrink-0 w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center">
                                <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                        @endif
                        <div>
                            <h3 class="text-lg font-semibold">pgBackRest Restore</h3>
                            <span @class([
                                'text-sm',
                                'text-blue-600 dark:text-blue-400' => $currentRestore->isRunning() || $currentRestore->isPending(),
                                'text-green-600 dark:text-green-400' => $currentRestore->isSuccess(),
                                'text-red-600 dark:text-red-400' => $currentRestore->isFailed(),
                            ])>
                                {{ ucfirst($currentRestore->status) }}
                            </span>
                        </div>
                        @if ($currentRestore->isFinished())
                            <button wire:click="closeRestoreProgress" class="ml-auto text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        @endif
                    </div>

                    {{-- Progress bar for running state --}}
                    @if ($currentRestore->isRunning() || $currentRestore->isPending())
                        <div class="h-1 bg-gray-200 dark:bg-coolgray-300 rounded-full mb-4 overflow-hidden">
                            <div class="h-full bg-blue-500 rounded-full animate-pulse" style="width: 100%"></div>
                        </div>
                    @endif

                    {{-- Backup label --}}
                    @if ($currentRestore->target_label)
                        <div class="p-3 bg-gray-100 dark:bg-coolgray-200 rounded mb-4">
                            <p class="text-sm font-medium mb-1">Backup Label:</p>
                            <code class="text-sm">{{ $currentRestore->target_label }}</code>
                        </div>
                    @endif

                    {{-- Status message --}}
                    @if ($currentRestore->message)
                        <div @class([
                            'p-3 rounded mb-4',
                            'bg-gray-100 dark:bg-coolgray-200' => $currentRestore->isRunning() || $currentRestore->isPending(),
                            'bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800' => $currentRestore->isSuccess(),
                            'bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800' => $currentRestore->isFailed(),
                        ])>
                            <p class="text-sm">{{ $currentRestore->message }}</p>
                        </div>
                    @endif

                    {{-- Info banner for running state --}}
                    @if ($currentRestore->isRunning() || $currentRestore->isPending())
                        <div class="p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded mb-4">
                            <p class="text-sm text-blue-800 dark:text-blue-200">
                                <svg class="w-4 h-4 inline-block mr-1" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                </svg>
                                The database is temporarily unavailable during restore. This modal will update automatically when the restore completes.
                            </p>
                        </div>
                    @endif

                    {{-- Log output (scrollable) --}}
                    @if ($currentRestore->log && ($currentRestore->isFailed() || $currentRestore->isSuccess()))
                        <div class="mb-4">
                            <p class="text-sm font-medium mb-2">Restore Log:</p>
                            <div class="max-h-48 overflow-y-auto p-3 bg-gray-900 dark:bg-black rounded">
                                <pre class="text-xs text-gray-300 whitespace-pre-wrap">{{ $currentRestore->log }}</pre>
                            </div>
                        </div>
                    @endif

                    {{-- Action buttons --}}
                    <div class="flex justify-end gap-3">
                        @if ($currentRestore->isFinished())
                            <x-forms.button wire:click="closeRestoreProgress">
                                {{ $currentRestore->isSuccess() ? 'Close' : 'Dismiss' }}
                            </x-forms.button>
                        @endif
                    </div>
                </div>
            </div>
        @endif
        @endcan
    @endisset
</div>

@script
<script>
    window.download_file = function(executionId) {
        window.open('/download/backup/' + executionId, '_blank');
    }
</script>
@endscript
