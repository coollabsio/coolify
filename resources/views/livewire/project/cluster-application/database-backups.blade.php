<div class="flex min-w-0 flex-col gap-6" @if ($restoreActive) wire:poll.5000ms @endif>
    @if (($restore['state'] ?? null) !== null)
        @php
            [$restoreTitle, $restoreType] = match ($restore['state']) {
                'queued' => ['Restore queued', 'info'],
                'running' => ['Restore in progress', 'warning'],
                'succeeded' => ['Restore finished', 'success'],
                default => ['Restore failed', 'danger'],
            };
        @endphp
        <x-callout :type="$restoreType" :title="$restoreTitle">
            Backup of {{ $restore['database'] ?? 'the database' }}
            @if (in_array($restore['state'], ['queued', 'running'], true) && ($restore['bytes_total'] ?? 0) > 0)
                · {{ formatBytes((int) ($restore['bytes_done'] ?? 0)) }} of {{ formatBytes((int) $restore['bytes_total']) }} downloaded
            @endif
            @if (filled($restore['updated_at'] ?? null))
                · {{ \Illuminate\Support\Carbon::parse($restore['updated_at'])->diffForHumans() }}
            @endif
            @if (filled($restore['error'] ?? null))
                <pre class="mt-2 max-h-32 overflow-y-auto text-[11px] break-words whitespace-pre-wrap">{{ $restore['error'] }}</pre>
            @endif
        </x-callout>
    @endif

    @if ($backup === null)
        <x-application.settings-section title="Database backups"
            description="Automate database backups and track the latest execution for each schedule.">
            @can('update', $workload)
                @if ($supportsDumps)
                    <x-slot:actions>
                        <x-modal-input title="New scheduled backup" :wireIgnore="false">
                            <x-slot:content>
                                <button type="button" class="button button-highlighted">
                                    <x-reicon name="plus" class="size-3.5" />
                                    Add
                                </button>
                            </x-slot:content>
                            <form wire:submit="createSchedule" class="application-settings-form flex flex-col gap-4">
                                <x-forms.input id="newFrequency" label="Frequency" required
                                    helper="Use every_minute, hourly, daily, weekly, monthly, yearly, or a cron expression." />
                                @if ($s3Options === [])
                                    <x-callout type="warning" title="No validated S3 storage">
                                        Add and validate an S3 storage of the team first.
                                        <a class="underline" {{ wireNavigate() }} href="{{ route('storage.index') }}">Open S3 storage</a>
                                    </x-callout>
                                @else
                                    <x-forms.listbox id="newS3StorageId" htmlId="cluster-database-backup-s3" label="S3 storage"
                                        placeholder="Select an S3 storage" :options="$s3Options" portal required />
                                @endif
                                <div class="flex justify-end pt-2">
                                    <x-forms.button type="submit" isHighlighted :disabled="$s3Options === []">Save</x-forms.button>
                                </div>
                            </form>
                        </x-modal-input>
                    </x-slot:actions>
                @endif
            @endcan

            @unless ($supportsDumps)
                <x-callout type="warning" title="Dump backups are not available">
                    ClickHouse has no dump command that streams one archive without S3 keys on the server. Use a volume backup that stops the container instead.
                </x-callout>
            @endunless

            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <p class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Schedules</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums text-neutral-950 dark:text-fg">{{ $schedules->count() }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Enabled</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums text-neutral-950 dark:text-fg">{{ $schedules->where('enabled', true)->count() }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Total executions</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums text-neutral-950 dark:text-fg">{{ $schedules->sum('executions_count') }}</p>
                </div>
            </div>
        </x-application.settings-section>

        @if ($schedules->isEmpty())
            <x-empty size="sm" title="No scheduled backups"
                description="Create a schedule to start protecting this database."
                icon-name="storages" />
        @else
            <div class="data-table overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.05]">
                <div class="data-table-header scheduled-backups-table-grid">
                    <span>Schedule</span>
                    <span>Latest run</span>
                    <span>S3 storage</span>
                    <span>Executions</span>
                    <span class="text-right">Actions</span>
                </div>
                @foreach ($schedules as $schedule)
                    @php
                        $latestStatus = data_get($schedule->latest_log, 'status');
                        [$statusLabel, $statusType] = match ($latestStatus) {
                            'success' => ['Success', 'success'],
                            'running' => ['In progress', 'warning'],
                            'failed' => ['Failed', 'error'],
                            default => ['Never run', 'neutral'],
                        };
                        $scheduleRoute = route('project.cluster-application.backup.show', [...$routeParameters, 'backup_uuid' => $schedule->uuid]);
                    @endphp
                    <div wire:key="cluster-database-backup-{{ $schedule->uuid }}"
                        class="data-table-row scheduled-backups-table-grid text-[13px] text-neutral-700 dark:text-fg-dim">
                        <a href="{{ $scheduleRoute }}" {{ wireNavigate() }} class="min-w-0">
                            <span class="block truncate font-medium text-neutral-950 dark:text-fg">{{ $schedule->frequency }}</span>
                            <span class="block truncate text-[12px] text-neutral-500 dark:text-fg-dim">
                                {{ $schedule->dump_all || ! $hasDatabases ? 'All databases' : ($schedule->databases_to_backup ?: 'Initial database') }}
                                · {{ $schedule->enabled ? 'Enabled' : 'Disabled' }}
                            </span>
                        </a>
                        <span>
                            <x-status-badge :status="$statusLabel" :type="$statusType" />
                            @if ($schedule->latest_log?->finished_at)
                                <span class="ml-1 text-[11px] text-neutral-500 dark:text-fg-faint">{{ $schedule->latest_log->finished_at->diffForHumans() }}</span>
                            @endif
                        </span>
                        <span class="truncate" title="{{ $schedule->s3?->name ?? 'S3 storage unavailable' }}">{{ $schedule->s3?->name ?? 'Unavailable' }}</span>
                        <span class="tabular-nums">{{ $schedule->executions_count }}</span>
                        <span class="flex justify-end">
                            <a href="{{ $scheduleRoute }}" {{ wireNavigate() }} class="icon-button" title="Open backup schedule" aria-label="Open backup schedule">
                                <x-reicon name="settings" class="size-3.5" />
                            </a>
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    @else
        <div class="flex items-center gap-2 text-[13px]">
            <a class="text-neutral-500 hover:text-black dark:text-fg-dim dark:hover:text-fg" {{ wireNavigate() }}
                href="{{ route('project.cluster-application.backups', $routeParameters) }}">Backups</a>
            <span class="text-neutral-400 dark:text-fg-faint">/</span>
            <span class="font-medium text-black dark:text-fg">{{ $backup->frequency }}</span>
        </div>

        @unless ($nodeSupportsDumps)
            <x-callout type="warning" title="The server cannot run dump backups">
                The server of this database does not report database.dump.v1 and database.restore.v1, or the database is not deployed. Upgrade Sentinel on it. Scheduled backups are skipped until then.
            </x-callout>
        @endunless

        <form wire:submit="save" class="application-settings-form flex flex-col gap-6">
            <x-application.settings-section title="Backup schedule"
                description="Choose when the database is dumped and how long each backup may run.">
                @can('update', $workload)
                    <x-slot:actions>
                        <div class="flex items-center gap-2">
                            @if (! $backup->enabled)
                                <x-forms.button type="button" wire:click="toggleEnabled" wire:loading.attr="disabled"
                                    wire:target="toggleEnabled" isHighlighted>Enable backup</x-forms.button>
                            @else
                                <x-forms.button type="button" wire:click="toggleEnabled" wire:loading.attr="disabled"
                                    wire:target="toggleEnabled">Disable backup</x-forms.button>
                            @endif
                            <x-forms.button type="button" wire:click="backupNow" wire:loading.attr="disabled"
                                wire:target="backupNow">Backup now</x-forms.button>
                        </div>
                    </x-slot:actions>
                @endcan

                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.input id="frequency" label="Frequency" required canGate="update" :canResource="$workload"
                        helper="Use every_minute, hourly, daily, weekly, monthly, yearly, or a cron expression." />
                    <x-forms.input id="timezone" label="Timezone" disabled
                        helper="Cluster backups use the instance timezone." />
                    @if ($hasDatabases)
                        <x-forms.input id="databasesToBackup" label="Databases to backup" canGate="update" :canResource="$workload"
                            :disabled="$dumpAll"
                            helper="Comma separated list of databases. Empty: the initial database of the container." />
                        <div class="flex items-end">
                            <x-forms.checkbox id="dumpAll" label="Backup all databases" canGate="update" :canResource="$workload"
                                helper="Dump every database of the instance into one archive." />
                        </div>
                    @endif
                    <x-forms.input id="timeout" type="number" min="60" max="36000" label="Timeout" required
                        canGate="update" :canResource="$workload" helper="Maximum backup runtime in seconds." />
                    <x-forms.input label="Missing backup alert after" id="missingBackupNotificationDays" type="number"
                        min="0" max="365" suffix="days" required canGate="update" :canResource="$workload"
                        helper="Notify through backup failure channels after this many days without an execution. Use 0 to disable." />
                </div>
            </x-application.settings-section>

            <x-application.settings-section title="S3 storage"
                description="Sentinel streams the dump from the server of the database to this storage. The server gets only presigned URLs, never the S3 key.">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-forms.listbox id="s3StorageId" htmlId="cluster-database-backup-schedule-s3" label="S3 storage"
                        placeholder="Select an S3 storage" :options="$s3Options" portal required
                        canGate="update" :canResource="$workload" />
                </div>
            </x-application.settings-section>

            <x-application.settings-section title="Retention"
                description="The first reached limit removes the oldest backup. Use 0 for unlimited retention.">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <x-forms.input label="Backups to keep" id="retentionAmountS3" type="number" min="0" required
                        canGate="update" :canResource="$workload" helper="Maximum number of recent S3 backups." />
                    <x-forms.input label="Days to keep" id="retentionDaysS3" type="number" min="0" required
                        canGate="update" :canResource="$workload" helper="Remove S3 backups older than this many days." />
                    <x-forms.input label="Maximum storage (GB)" id="retentionMaxStorageS3" type="number" min="0" step="any" required
                        canGate="update" :canResource="$workload" helper="Remove the oldest S3 backups after this total size is reached." />
                </div>
            </x-application.settings-section>

            @can('update', $workload)
                <div class="flex justify-end">
                    <x-forms.button type="submit" isHighlighted>Save</x-forms.button>
                </div>
            @endcan
        </form>

        @php
            $executionCount = $executions->total();
            $hasRunning = $executions->getCollection()->contains('status', 'running');
        @endphp
        <div @if ($hasRunning && ! $restoreActive) wire:poll.5000ms @endif>
            <x-application.settings-section title="Executions"
                description="Dumps in S3, with their size and SHA-256 checksum. Import one into the running database." flush>
                @if ($executionCount > 0)
                    <div class="data-table w-full overflow-x-auto">
                        <div class="data-table-header grid grid-cols-[6.5rem_6rem_minmax(11rem,1fr)_9.5rem_5rem_4.5rem] border-b border-neutral-200 bg-neutral-50 dark:border-white/[0.08] dark:bg-white/[0.05]">
                            <span>Status</span>
                            <span>Database</span>
                            <span>Archive</span>
                            <span>Time</span>
                            <span>Size</span>
                            <span class="text-right">Actions</span>
                        </div>
                        @foreach ($executions as $execution)
                            @php
                                $finishedAt = $execution->finished_at ?? $execution->updated_at;
                                $live = $execution->status === 'success' && $execution->s3_uploaded === true && ! $execution->s3_storage_deleted;
                            @endphp
                            <div wire:key="cluster-database-backup-execution-{{ $execution->id }}"
                                class="data-table-row grid grid-cols-[6.5rem_6rem_minmax(11rem,1fr)_9.5rem_5rem_4.5rem] min-h-16 items-center gap-x-3 border-b border-neutral-200 text-[12px] last:border-b-0 dark:border-white/[0.07]">
                                <span>
                                    <x-status-badge :status="$execution->status === 'running' ? 'In progress' : str($execution->status)->headline()->toString()"
                                        :type="match ($execution->status) { 'running' => 'warning', 'success' => 'success', 'failed' => 'error', default => 'neutral' }"
                                        :pulse="$execution->status === 'running'" />
                                </span>
                                <span class="truncate text-neutral-950 dark:text-fg">{{ $execution->database_name ?? '-' }}</span>
                                <span class="min-w-0">
                                    <x-forms.copy-input :text="$execution->filename ?? 'No archive name'" />
                                    @if ($execution->sha256)
                                        <span class="mt-1 block truncate font-mono text-[10px] text-neutral-500 dark:text-fg-faint" title="SHA-256 {{ $execution->sha256 }}">sha256 {{ $execution->sha256 }}</span>
                                    @endif
                                </span>
                                <span class="text-[11px] text-neutral-500 dark:text-fg-faint">
                                    @if ($execution->status === 'running')
                                        Running for {{ calculateDuration($execution->created_at, now()) }}
                                    @else
                                        <span class="block text-black dark:text-fg">{{ $finishedAt->format('Y-m-d H:i:s') }}</span>
                                        {{ $finishedAt->diffForHumans() }} · {{ calculateDuration($execution->created_at, $finishedAt) }}
                                    @endif
                                </span>
                                <span class="tabular-nums text-neutral-500 dark:text-fg-dim">{{ $execution->size > 0 ? formatBytes($execution->size) : '-' }}</span>
                                <span class="flex items-center justify-end gap-1">
                                    @if ($live && $execution->sha256 && ! $restoreActive)
                                        <x-modal-confirmation title="Import this backup?" canGate="update" :canResource="$workload"
                                            submitAction="restore({{ $execution->id }})"
                                            :actions="['The dump is imported into the running database '.$workload->name.'.', 'Tables, collections, or keys in the dump replace the current ones.', 'Data that is not in the dump can stay. Redis, KeyDB, and Dragonfly replace the whole dataset.']"
                                            confirmationText="{{ $workload->name }}"
                                            confirmationLabel="Please confirm the execution of the actions by entering the Database Name below"
                                            shortConfirmationLabel="Database Name">
                                            <x-slot:trigger>
                                                <button type="button" class="icon-button shrink-0" title="Import backup" aria-label="Import backup">
                                                    <x-reicon name="time-back" class="size-3.5" />
                                                </button>
                                            </x-slot:trigger>
                                        </x-modal-confirmation>
                                    @endif
                                    @if ($execution->status !== 'running')
                                        <x-modal-confirmation title="Confirm Backup Deletion?" isErrorButton canGate="delete" :canResource="$workload"
                                            submitAction="deleteBackup({{ $execution->id }})"
                                            :checkboxes="$live ? [['id' => 'delete_backup_s3', 'label' => 'Delete the archive permanently from S3']] : []"
                                            :actions="['This backup execution record will be deleted.']"
                                            confirmationText="{{ $execution->filename ?? $execution->uuid }}"
                                            confirmationLabel="Please confirm the execution of the actions by entering the Backup Filename below"
                                            shortConfirmationLabel="Backup Filename">
                                            <x-slot:trigger>
                                                <button type="button" class="icon-button shrink-0 text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300"
                                                    title="Delete backup" aria-label="Delete backup">
                                                    <x-reicon name="trash" class="size-3.5" />
                                                </button>
                                            </x-slot:trigger>
                                        </x-modal-confirmation>
                                    @endif
                                </span>
                                @if ($execution->message)
                                    <pre class="volume-backup-execution-message col-span-6 min-w-0 max-w-full max-h-20 overflow-y-auto overflow-x-hidden bg-transparent py-2 text-[11px] break-words whitespace-pre-wrap text-neutral-600 dark:text-fg-dim">{{ $execution->message }}</pre>
                                @endif
                            </div>
                        @endforeach
                        <x-table-pagination :from="$executions->firstItem() ?? 0" :to="$executions->lastItem() ?? 0"
                            :total="$executionCount" :current-page="$executions->currentPage()" :last-page="$executions->lastPage()"
                            wire-target="previousPage,nextPage" previous-action="previousPage" next-action="nextPage" />
                    </div>
                @else
                    <x-empty size="sm" title="No executions" description="Run the backup schedule to create its first execution."
                        icon-name="storages" />
                @endif
            </x-application.settings-section>
        </div>

        @can('delete', $workload)
            <x-application.settings-section title="Danger zone" description="Delete this backup schedule.">
                <x-modal-confirmation title="Confirm Backup Schedule Deletion?" isErrorButton buttonTitle="Delete backup schedule"
                    submitAction="deleteSchedule"
                    :checkboxes="[['id' => 'delete_associated_backups_s3', 'label' => 'Delete all S3 archives created by this schedule.']]"
                    :actions="['The backup schedule will be deleted.']"
                    confirmationText="{{ $workload->name }}"
                    confirmationLabel="Please confirm the execution of the actions by entering the Database Name below"
                    shortConfirmationLabel="Database Name" />
            </x-application.settings-section>
        @endcan
    @endif
</div>
