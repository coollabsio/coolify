<div class="flex min-w-0 flex-col gap-6">
    @if ($backup === null)
        <x-application.settings-section title="Storage backups"
            helper="Schedule backups of the volumes of this application to S3. Each backup is a full copy of the volume.">
            @can('update', $workload)
                <x-slot:actions>
                    @if ($newVolumeOptions !== [])
                        <x-modal-input title="New scheduled backup" :wireIgnore="false">
                            <x-slot:content>
                                <button type="button" class="button button-highlighted">
                                    <x-reicon name="plus" class="size-3.5" />
                                    Add
                                </button>
                            </x-slot:content>
                            <form wire:submit="createSchedule" class="application-settings-form flex flex-col gap-4">
                                <x-forms.listbox id="newVolumeUuid" htmlId="cluster-volume-backup-volume" label="Volume"
                                    placeholder="Select a volume" :options="$newVolumeOptions" portal required />
                                <x-forms.input id="newFrequency" label="Frequency" required
                                    helper="Use every_minute, hourly, daily, weekly, monthly, yearly, or a cron expression." />
                                @if ($s3Options === [])
                                    <x-callout type="warning" title="No validated S3 storage">
                                        Add and validate an S3 storage of the team first.
                                        <a class="underline" {{ wireNavigate() }} href="{{ route('storage.index') }}">Open S3 storage</a>
                                    </x-callout>
                                @else
                                    <x-forms.listbox id="newS3StorageId" htmlId="cluster-volume-backup-s3" label="S3 storage"
                                        placeholder="Select an S3 storage" :options="$s3Options" portal required />
                                @endif
                                <div class="flex justify-end pt-2">
                                    <x-forms.button type="submit" isHighlighted :disabled="$s3Options === []">Save</x-forms.button>
                                </div>
                            </form>
                        </x-modal-input>
                    @endif
                </x-slot:actions>
            @endcan

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

        <x-application.settings-section title="Schedules" helper="Volume backup schedules of this application."
            :flush="$schedules->isNotEmpty()">
            @if ($schedules->isNotEmpty())
                <div class="data-table flex w-full flex-col overflow-x-auto">
                    <div class="data-table-header backup-table-grid">
                        <span>Target</span>
                        <span>Type</span>
                        <span>Schedule</span>
                        <span>Status</span>
                        <span>S3</span>
                        <span>Last run</span>
                        <span class="justify-self-end">Executions</span>
                    </div>
                    @foreach ($schedules as $schedule)
                        @php
                            $latestExecution = $schedule->latestExecution;
                            $status = $latestExecution?->status;
                        @endphp
                        <a wire:key="cluster-volume-backup-{{ $schedule->uuid }}"
                            href="{{ route('project.cluster-application.backup.show', [...$routeParameters, 'backup_uuid' => $schedule->uuid]) }}"
                            {{ wireNavigate() }}
                            class="data-table-row backup-table-grid text-[13px] text-neutral-700 dark:text-fg-dim">
                            <span class="min-w-0 truncate font-medium text-neutral-950 dark:text-fg" title="{{ $schedule->targetName() }}">
                                {{ $schedule->targetName() }}
                            </span>
                            <span>{{ $schedule->targetType() }}</span>
                            <span>{{ $schedule->frequency }}</span>
                            <span>
                                <x-status-badge :status="match ($status) {
                                    'running' => 'In progress',
                                    'success' => 'Success',
                                    'failed' => 'Failed',
                                    default => $schedule->enabled ? 'Waiting' : 'Disabled',
                                }" :type="match ($status) {
                                    'running' => 'warning',
                                    'success' => 'success',
                                    'failed' => 'error',
                                    default => 'neutral',
                                }" />
                            </span>
                            <span title="{{ $schedule->s3?->name ?? 'S3 storage unavailable' }}">
                                <x-status-badge :status="$schedule->s3 ? 'Configured' : 'Unavailable'" :type="$schedule->s3 ? 'success' : 'error'" />
                            </span>
                            <span>{{ $latestExecution?->finished_at?->diffForHumans() ?? ($status === 'running' ? 'Running now' : 'Never') }}</span>
                            <span class="text-right tabular-nums text-neutral-950 dark:text-fg">{{ $schedule->executions_count }}</span>
                        </a>
                    @endforeach
                </div>
            @else
                <x-empty size="sm" title="No scheduled backups"
                    description="Add a volume backup schedule to protect the data of this application."
                    icon-name="storages" />
            @endif
        </x-application.settings-section>
    @else
        <div class="flex items-center gap-2 text-[13px]">
            <a class="text-neutral-500 hover:text-black dark:text-fg-dim dark:hover:text-fg" {{ wireNavigate() }}
                href="{{ route('project.cluster-application.backups', $routeParameters) }}">Backups</a>
            <span class="text-neutral-400 dark:text-fg-faint">/</span>
            <span class="font-medium text-black dark:text-fg">{{ $backup->targetName() }}</span>
        </div>

        @unless ($nodeSupportsBackups)
            <x-callout type="warning" title="The server cannot run backups">
                The server of this volume does not report volume.backup.v1 and volume.restore.v1. Upgrade Sentinel on it. Scheduled backups are skipped until then.
            </x-callout>
        @endunless

        <form wire:submit="save" class="application-settings-form flex flex-col gap-6">
            <x-application.settings-section title="Backup schedule"
                description="Choose when this volume is archived and how long each backup may run.">
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
                                wire:target="backupNow">Back up now</x-forms.button>
                        </div>
                    </x-slot:actions>
                @endcan

                <div class="mb-4 flex items-center justify-between gap-3 rounded-lg bg-neutral-50 px-3 py-2.5 ring-1 ring-neutral-200 dark:bg-white/[0.05] dark:ring-white/[0.07]">
                    <span class="text-[12px] text-neutral-500 dark:text-fg-dim">{{ $backup->targetType() }}</span>
                    <span class="truncate text-[12px] font-medium text-neutral-900 dark:text-fg">
                        {{ $backup->targetName() }} · {{ $backup->backupable?->mount_path }} · {{ $backup->backupable?->node?->name ?? 'not created yet' }}
                    </span>
                </div>

                <x-callout type="warning" title="File-level consistency">
                    A backup of a running application is crash-consistent: data that the application writes during the backup can be inconsistent. Stopping the container during the backup is safer, but briefly interrupts the application.
                    <div class="mt-4">
                        <x-forms.listbox id="stopDuringBackup" label="Archive behavior" live onChange="instantSave"
                            canGate="update" :canResource="$workload" :options="[
                                ['value' => false, 'label' => 'Keep the container running'],
                                ['value' => true, 'label' => 'Stop the container during the backup'],
                            ]" />
                    </div>
                </x-callout>

                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <x-forms.input id="frequency" label="Frequency" required canGate="update" :canResource="$workload"
                        helper="Use every_minute, hourly, daily, weekly, monthly, yearly, or a cron expression." />
                    <x-forms.input id="timezone" label="Timezone" disabled
                        helper="Cluster backups use the instance timezone." />
                    <x-forms.input id="timeout" type="number" min="60" max="36000" label="Timeout" required
                        canGate="update" :canResource="$workload" helper="Maximum backup runtime in seconds." />
                    <x-forms.input label="Missing backup alert after" id="missingBackupNotificationDays" type="number"
                        min="0" max="365" suffix="days" required canGate="update" :canResource="$workload"
                        helper="Notify through backup failure channels after this many days without an execution. Use 0 to disable." />
                </div>
            </x-application.settings-section>

            <x-application.settings-section title="S3 storage"
                description="The volume is streamed from its server to this storage. The server gets only presigned URLs, never the S3 key.">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-forms.listbox id="s3StorageId" htmlId="cluster-volume-backup-schedule-s3" label="S3 storage"
                        placeholder="Select an S3 storage" :options="$s3Options" portal required
                        canGate="update" :canResource="$workload" />
                </div>
            </x-application.settings-section>

            <x-application.settings-section title="Retention"
                description="The first reached limit removes the oldest archive. Use 0 for unlimited retention.">
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
        <div @if ($hasRunning) wire:poll.5000ms @endif>
            <x-application.settings-section title="Executions"
                description="Archives in S3, with their size and SHA-256 checksum. Restore one to a server of the cluster." flush>
                @if ($executionCount > 0)
                    <div class="data-table w-full overflow-x-auto">
                        <div class="data-table-header grid grid-cols-[6.5rem_minmax(11rem,1fr)_9.5rem_5rem_8rem_4.5rem] border-b border-neutral-200 bg-neutral-50 dark:border-white/[0.08] dark:bg-white/[0.05]">
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
                                $live = $execution->status === 'success' && $execution->s3_uploaded === true && ! $execution->s3_storage_deleted;
                            @endphp
                            <div wire:key="cluster-volume-backup-execution-{{ $execution->id }}"
                                class="data-table-row grid grid-cols-[6.5rem_minmax(11rem,1fr)_9.5rem_5rem_8rem_4.5rem] min-h-16 items-center gap-x-3 border-b border-neutral-200 text-[12px] last:border-b-0 dark:border-white/[0.07]">
                                <span>
                                    <x-status-badge :status="$execution->status === 'running' ? 'In progress' : str($execution->status)->headline()->toString()"
                                        :type="match ($execution->status) { 'running' => 'warning', 'success' => 'success', 'failed' => 'error', default => 'neutral' }"
                                        :pulse="$execution->status === 'running'" />
                                </span>
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
                                <span class="flex flex-wrap gap-1">
                                    @foreach ($execution->s3Replicas as $replica)
                                        <x-status-badge wire:key="cluster-volume-backup-replica-{{ $replica->id }}"
                                            :label="$replica->s3?->name ?? 'Removed S3 storage'"
                                            :status="$replica->s3_storage_deleted ? 'deleted' : match ($replica->s3_uploaded) {
                                                true => null,
                                                false => 'failed',
                                                default => $execution->status === 'running' ? 'pending' : 'not uploaded',
                                            }"
                                            :type="$replica->s3_storage_deleted || $replica->s3_uploaded === null ? 'neutral' : ($replica->s3_uploaded ? 'success' : 'error')" />
                                    @endforeach
                                </span>
                                <span class="flex items-center justify-end gap-1">
                                    @can('update', $workload)
                                        @if ($live && $restoreOptions !== [])
                                            <x-modal-input title="Restore backup" :subtitle="$backup->targetName()" :wireIgnore="false">
                                                <x-slot:content>
                                                    <button type="button" class="icon-button shrink-0" title="Restore backup" aria-label="Restore backup">
                                                        <x-reicon name="time-back" class="size-3.5" />
                                                    </button>
                                                </x-slot:content>
                                                <form wire:submit="restore({{ $execution->id }})" class="flex flex-col gap-4">
                                                    <x-forms.listbox id="restoreNodeUuid" htmlId="cluster-volume-restore-node-{{ $execution->id }}"
                                                        label="Restore to server" placeholder="Select a server" :options="$restoreOptions" portal required />
                                                    <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                                                        The backup is downloaded into a new volume while the application runs. Then the application stops, switches to the restored volume, and starts again. On another server, the other volumes move with the application. The old volume is kept for 7 days.
                                                    </p>
                                                    <div class="flex justify-end">
                                                        <x-forms.button type="submit" isHighlighted wire:target="restore">Restore</x-forms.button>
                                                    </div>
                                                </form>
                                            </x-modal-input>
                                        @endif
                                    @endcan
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
                    confirmationText="{{ $backup->targetName() }}"
                    confirmationLabel="Please confirm the execution of the actions by entering the volume name below"
                    shortConfirmationLabel="Volume name" />
            </x-application.settings-section>
        @endcan
    @endif
</div>
