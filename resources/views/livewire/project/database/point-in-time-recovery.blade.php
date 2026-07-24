<div wire:poll.10s>
    <x-slot:title>
        {{ data_get_str($database, 'name')->limit(10) }} > Point-in-Time Recovery | Coolify
    </x-slot>
    <h1>Point-in-Time Recovery</h1>
    <livewire:project.shared.configuration-checker :resource="$database" />
    <livewire:project.database.heading :database="$database" />

    <div class="flex flex-col gap-8">
        <section class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center gap-2">
                <h2>WAL-G Configuration</h2>
                <x-status-badge
                    status="{{ str($configuration->status)->headline() }}"
                    type="{{ match ($configuration->status) {
                        'healthy' => 'success',
                        'warning', 'pending_restart' => 'warning',
                        default => 'error',
                    } }}" />
                <x-status-badge status="{{ $configuration->enabled ? 'Enabled' : 'Disabled' }}"
                    type="{{ $configuration->enabled ? 'success' : 'warning' }}" />
            </div>

            <div class="grid grid-cols-1 gap-4 rounded border border-neutral-200 p-4 dark:border-coolgray-300 md:grid-cols-2 xl:grid-cols-3">
                <x-forms.select id="s3StorageUuid" label="S3 Storage" required wire:model="s3StorageUuid"
                    :disabled="$storageAttached"
                    helper="{{ $storageAttached ? 'Active PITR storage cannot be changed in v1.' : 'Attach a usable storage, then apply and restart PostgreSQL.' }}">
                    <option value="">Select storage</option>
                    @foreach ($s3Storages as $storage)
                        <option wire:key="pitr-storage-{{ $storage['uuid'] }}" value="{{ $storage['uuid'] }}">
                            {{ $storage['name'] }}{{ $storage['is_usable'] ? '' : ' (unavailable)' }}
                        </option>
                    @endforeach
                </x-forms.select>
                <x-forms.input id="baseBackupFrequency" label="Base Backup Frequency" required
                    helper="Cron or human expression, such as daily." />
                <x-forms.input id="archiveTimeoutSeconds" type="number" min="1" max="86400"
                    label="Archive Timeout (seconds)" required />
                <x-forms.select id="walLevel" label="WAL Level" required wire:model="walLevel"
                    helper="Switching from logical to replica can prevent PostgreSQL from starting while logical replication slots exist.">
                    <option value="replica">Replica</option>
                    <option value="logical">Logical</option>
                </x-forms.select>
                <x-forms.input id="retentionFullBackups" type="number" min="1" max="1000"
                    label="Full Backups to Retain" required />
                <x-forms.input id="timeout" type="number" min="60" max="36000" label="Operation Timeout (seconds)"
                    required />
            </div>

            <div class="flex flex-wrap gap-2">
                <x-forms.button wire:click="save" wire:loading.attr="disabled" wire:target="save"
                    canGate="manageBackups" :canResource="$database">
                    Save Settings
                </x-forms.button>
                <x-forms.button wire:click="applyAndRestart" wire:loading.attr="disabled"
                    wire:target="applyAndRestart" canGate="manageBackups" :canResource="$database">
                    Apply and Restart
                </x-forms.button>
                <x-forms.button wire:click="runBaseBackup" wire:loading.attr="disabled"
                    wire:target="runBaseBackup" canGate="manageBackups" :canResource="$database">
                    Run Base Backup Now
                </x-forms.button>
                <x-forms.button wire:click="runHealthCheck" wire:loading.attr="disabled"
                    wire:target="runHealthCheck" canGate="manageBackups" :canResource="$database">
                    Run Health Check Now
                </x-forms.button>
            </div>
            @error('baseBackup')
                <div class="text-error">{{ $message }}</div>
            @enderror
        </section>

        <section class="flex flex-col gap-4">
            <h2>Status</h2>
            <div class="grid grid-cols-1 gap-4 rounded border border-neutral-200 p-4 dark:border-coolgray-300 sm:grid-cols-2 xl:grid-cols-3">
                <div>
                    <div class="text-sm text-neutral-500 dark:text-neutral-400">WAL-G Image</div>
                    <div>{{ $imageReady ? 'Ready' : 'Unsupported or mismatched' }}</div>
                </div>
                <div>
                    <div class="text-sm text-neutral-500 dark:text-neutral-400">Last Successful Base Backup</div>
                    <div>{{ $configuration->last_successful_base_backup_at?->toIso8601String() ?? 'Never' }}</div>
                </div>
                <div>
                    <div class="text-sm text-neutral-500 dark:text-neutral-400">Last Archived WAL</div>
                    <div>{{ $configuration->last_archived_wal ?? 'Not reported' }}</div>
                </div>
                <div>
                    <div class="text-sm text-neutral-500 dark:text-neutral-400">Last Archive Failure</div>
                    <div>{{ $configuration->last_failed_at?->toIso8601String() ?? 'None' }}</div>
                </div>
                <div class="sm:col-span-2">
                    <div class="text-sm text-neutral-500 dark:text-neutral-400">Health Message</div>
                    <div>{{ $configuration->last_health_message ?? 'No health check has completed.' }}</div>
                </div>
            </div>
        </section>

        <section class="flex flex-col gap-4">
            <div>
                <h2>Restore to Timestamp</h2>
                <div>Creates a new PostgreSQL database in this project and environment. The source is not changed.</div>
            </div>
            <form wire:submit="restore" class="grid grid-cols-1 items-end gap-4 rounded border border-neutral-200 p-4 dark:border-coolgray-300 md:grid-cols-2">
                <x-forms.input id="restoreTargetTime" label="Target Time (UTC)" required
                    placeholder="2026-07-24T12:34:56Z" />
                <x-forms.input id="restoreName" label="New Database Name" required />
                <div class="md:col-span-2">
                    <x-forms.input id="restoreDescription" label="Description" />
                </div>
                <div class="md:col-span-2">
                    <x-forms.button type="submit" wire:loading.attr="disabled" wire:target="restore"
                        canGate="manageBackups" :canResource="$database">
                        Restore to Timestamp
                    </x-forms.button>
                </div>
            </form>
        </section>

        <section class="flex flex-col gap-4 pb-16">
            <h2>Recent WAL-G Operations</h2>
            <div class="overflow-x-auto rounded border border-neutral-200 dark:border-coolgray-300">
                <table class="min-w-full text-left text-sm">
                    <thead class="border-b border-neutral-200 dark:border-coolgray-300">
                        <tr>
                            <th class="p-3">Operation</th>
                            <th class="p-3">Status</th>
                            <th class="p-3">Started</th>
                            <th class="p-3">Target / Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($executions as $execution)
                            <tr wire:key="pitr-execution-{{ $execution->uuid }}"
                                class="border-b border-neutral-200 last:border-b-0 dark:border-coolgray-300">
                                <td class="p-3">{{ str($execution->operation)->headline() }}</td>
                                <td class="p-3">{{ str($execution->status)->headline() }}</td>
                                <td class="p-3">{{ $execution->started_at?->toIso8601String() }}</td>
                                <td class="max-w-xl p-3">
                                    @if ($execution->restoredDatabase)
                                        Restored as {{ $execution->restoredDatabase->name }}
                                    @elseif ($execution->target_time)
                                        Target {{ $execution->target_time->toIso8601String() }}
                                    @else
                                        {{ $execution->message ?? 'In progress' }}
                                    @endif
                                    @if ($execution->message && ($execution->target_time || $execution->restoredDatabase))
                                        <div class="pt-1 text-neutral-500 dark:text-neutral-400">
                                            {{ $execution->message }}
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="p-3 text-neutral-500 dark:text-neutral-400">
                                    No WAL-G operations recorded yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
