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
                        @endif
                    </div>
                    <div class="text-gray-600 dark:text-gray-400 text-sm">
                        Location: {{ data_get($execution, 'filename', 'N/A') }}
                    </div>
                    <div class="flex items-center gap-3 mt-2">
                        <div class="text-gray-600 dark:text-gray-400 text-sm">
                            Backup Availability:
                        </div>
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
                    </div>
                    @if (data_get($execution, 'message'))
                        <div class="mt-2 p-2 bg-gray-100 dark:bg-coolgray-200 rounded-sm">
                            <pre class="whitespace-pre-wrap text-sm">{{ data_get($execution, 'message') }}</pre>
                        </div>
                    @endif
                    <div class="flex gap-2 mt-4">
                        @if (data_get($execution, 'status') === 'success')
                            <x-forms.button class="dark:hover:bg-coolgray-400"
                                x-on:click="download_file('{{ data_get($execution, 'id') }}')">Download</x-forms.button>
                        @endif
                        @php
                            $executionCheckboxes = [];
                            $deleteActions = [];

                            if (!data_get($execution, 'local_storage_deleted', false)) {
                                $deleteActions[] = 'This backup will be permanently deleted from local storage.';
                            }

                            if (data_get($execution, 's3_uploaded') === true && !data_get($execution, 's3_storage_deleted', false)) {
                                $executionCheckboxes[] = ['id' => 'delete_backup_s3', 'label' => 'Delete the selected backup permanently from S3 Storage'];
                            }

                            if (empty($deleteActions)) {
                                $deleteActions[] = 'This backup execution record will be deleted.';
                            }
                        @endphp
                        <x-modal-confirmation title="Confirm Backup Deletion?" buttonTitle="Delete" isErrorButton
                            submitAction="deleteBackup({{ data_get($execution, 'id') }})" :checkboxes="$executionCheckboxes"
                            :actions="$deleteActions" confirmationText="{{ data_get($execution, 'filename') }}"
                            confirmationLabel="Please confirm the execution of the actions by entering the Backup Filename below"
                            shortConfirmationLabel="Backup Filename" 1 />
                    </div>
                </div>
            @empty
                <div class="p-4 bg-gray-100 dark:bg-coolgray-100 rounded-sm">No executions found.</div>
            @endforelse
        </div>
        <script>
            function download_file(executionId) {
                window.open('/download/backup/' + executionId, '_blank');
            }
        </script>
    @endisset
</div>
