<div class="flex flex-col gap-4">
    <form wire:submit="save" class="flex flex-col gap-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
            <div>
                <div class="flex gap-3">
            <h2>Scheduled Backup</h2>
            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                <x-forms.button type="submit" class="w-full sm:w-auto">Save</x-forms.button>
                @if (!$enabled)
                    <x-forms.button type="button" wire:click="toggleEnabled" wire:loading.attr="disabled"
                        wire:target="toggleEnabled" isHighlighted>Enable Backup</x-forms.button>
                @else
                    <x-forms.button type="button" wire:click="toggleEnabled" wire:loading.attr="disabled"
                        wire:target="toggleEnabled">Disable Backup</x-forms.button>
                @endif

                <x-forms.button type="button" wire:click="backupNow" class="w-full sm:w-auto">Backup Now</x-forms.button>
                @if ($backup)
                    <x-modal-confirmation title="Confirm Backup Schedule Deletion?" isErrorButton submitAction="delete"
                        :actions="['The selected backup schedule will be deleted.', 'All local and S3 archives created by this schedule will be deleted.']"
                        confirmationText="{{ $storage->name }}"
                        confirmationLabel="Please confirm the execution of the actions by entering the Volume Name below"
                        shortConfirmationLabel="Volume Name">
                        <x-slot:trigger>
                            <x-forms.button isError class="w-full sm:w-auto">Delete Backups and Schedule</x-forms.button>
                        </x-slot:trigger>
                    </x-modal-confirmation>
                @endif
            </div>
                </div>
            <p class="pt-1 text-sm text-neutral-600 dark:text-neutral-400">
                Persistent volume:
                <span class="font-medium text-neutral-800 dark:text-neutral-200">{{ $storage->name }}</span>
            </p>
            </div>

        </div>

        <div class="w-full max-w-md">
            <x-forms.checkbox id="pauseDuringBackup" label="Pause containers while creating the archive"
                helper="Off by default. Containers using this volume are resumed immediately after the archive is created." />
            @if ($availableS3Storages->isNotEmpty())
                <x-forms.checkbox instantSave id="saveToS3" label="S3 Enabled" />
            @elseif ($saveToS3)
                <x-forms.checkbox instantSave id="saveToS3" label="S3 Enabled"
                    helper="The configured S3 storage is no longer available. Disable S3 backups or configure a usable S3 storage." />
            @else
                <x-forms.checkbox instantSave id="saveToS3" label="S3 Enabled"
                    helper="No validated S3 storage available." disabled />
            @endif
            @if ($saveToS3)
                <x-forms.checkbox instantSave id="disableLocalBackup" label="Disable Local Backup"
                    helper="When enabled, backup files are deleted locally after a successful S3 upload." />
            @else
                <x-forms.checkbox id="disableLocalBackup" label="Disable Local Backup"
                    helper="When enabled, backup files are deleted locally after a successful S3 upload." disabled />
            @endif
        </div>

        <div class="w-full max-w-md pb-2">
            <div class="flex items-center gap-1 mb-1 text-sm font-medium">
                <span>S3 Storage</span>
                @if (!$saveToS3)
                    <span class="text-xs font-normal text-warning">(currently disabled)</span>
                @else
                    <x-highlighted text="*" />
                @endif
            </div>
            <x-forms.select id="s3StorageId" wire:model.live="s3StorageId" :required="$saveToS3"
                :disabled="$availableS3Storages->isEmpty()">
                @if ($availableS3Storages->isEmpty())
                    <option value="">No S3 storage available</option>
                @else
                    @foreach ($availableS3Storages as $s3Storage)
                        <option value="{{ $s3Storage->id }}">{{ $s3Storage->name }}</option>
                    @endforeach
                @endif
            </x-forms.select>
        </div>

        <div class="flex flex-col gap-4">
            <h3>Settings</h3>
            <div class="p-3 text-sm rounded bg-warning/10 text-warning">
                Backups made while the application is writing to this volume may be inconsistent or corrupted. You can
                pause containers during the archive step for a more consistent backup, but this briefly interrupts the
                application.
            </div>
            <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
                <x-forms.input id="frequency" label="Frequency" required
                    helper="Use every_minute, hourly, daily, weekly, monthly, yearly, or a cron expression." />
                <x-forms.input id="timezone" label="Timezone" disabled
                    helper="The timezone of the server where the backup is scheduled to run (if not set, the instance timezone will be used)" required />
                <x-forms.input id="timeout" type="number" min="60" max="36000" label="Timeout"
                    helper="The timeout of the backup job in seconds." required />
            </div>


            <h3 class="mt-6 mb-2 text-lg font-medium">Backup Retention Settings</h3>
            <div class="mb-4">
                <ul class="pl-6 space-y-2 list-disc">
                    <li>Setting a value to 0 means unlimited retention.</li>
                    <li>The retention rules work independently - whichever limit is reached first will trigger cleanup.</li>
                </ul>
            </div>

            <div class="flex flex-col gap-6">
                <div>
                    <h4 class="mb-3 font-medium">Local Backup Retention</h4>
                    <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
                        <x-forms.input label="Number of backups to keep" id="retentionAmountLocally" type="number"
                            min="0"
                            helper="Keeps only the specified number of most recent backups on the server. Set to 0 for unlimited backups."
                            required />
                        <x-forms.input label="Days to keep backups" id="retentionDaysLocally" type="number"
                            min="0"
                            helper="Automatically removes backups older than the specified number of days. Set to 0 for no time limit."
                            required />
                        <x-forms.input label="Maximum storage (GB)" id="retentionMaxStorageLocally" type="number"
                            min="0" step="any"
                            helper="When total size of all backups in the current backup job exceeds this limit in GB, the oldest backups will be removed. Decimal values are supported (e.g. 0.001 for 1MB). Set to 0 for unlimited storage."
                            required />
                    </div>
                </div>
                @if ($saveToS3)
                    <div>
                        <h4 class="mb-3 font-medium">S3 Storage Retention</h4>
                        <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
                            <x-forms.input label="Number of backups to keep" id="retentionAmountS3" type="number"
                                min="0"
                                helper="Keeps only the specified number of most recent backups on S3 storage. Set to 0 for unlimited backups."
                                required />
                            <x-forms.input label="Days to keep backups" id="retentionDaysS3" type="number"
                                min="0"
                                helper="Automatically removes S3 backups older than the specified number of days. Set to 0 for no time limit."
                                required />
                            <x-forms.input label="Maximum storage (GB)" id="retentionMaxStorageS3" type="number"
                                min="0" step="any"
                                helper="When total size of all backups in the current backup job exceeds this limit in GB, the oldest backups will be removed. Decimal values are supported (e.g. 0.5 for 500MB). Set to 0 for unlimited storage."
                                required />
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </form>

    @if ($backup)
        @php
            $executionCount = method_exists($executions, 'total') ? $executions->total() : $executions->count();
            $currentPage = method_exists($executions, 'currentPage') ? $executions->currentPage() : 1;
            $lastPage = method_exists($executions, 'lastPage') ? $executions->lastPage() : 1;
        @endphp
        <div wire:poll.5000ms="$refresh">
            <div class="flex flex-col gap-3 py-4 sm:flex-row sm:flex-wrap sm:items-center">
                <h3 class="py-0">Executions <span class="text-xs">({{ $executionCount }})</span></h3>
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

            <div class="flex flex-col gap-4">
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
                            • Volume: {{ $storage->name }}
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
</div>

@script
<script>
    window.download_volume_backup_file = function(executionId) {
        window.open('/download/volume-backup/' + executionId, '_blank');
    }
</script>
@endscript
