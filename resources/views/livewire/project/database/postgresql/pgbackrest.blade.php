<div>
    <form wire:submit="submit" class="flex flex-col gap-4">
        <div class="flex items-center gap-2">
            <h2>pgBackRest</h2>
            @if ($pgbackrestEnabled)
                <x-forms.button type="submit">Save</x-forms.button>
            @endif
        </div>

        @if (!$pgbackrestEnabled)
            <div class="pb-4">
                <p class="text-sm text-neutral-500">
                    pgBackRest is an advanced backup and restore solution for PostgreSQL databases.
                    It provides features like incremental backups, parallel backup/restore, and backup verification.
                </p>
            </div>
        @endif

        <div class="w-64">
            <x-forms.checkbox wire:click="togglePgbackrest" wire:model="pgbackrestEnabled"
                label="Enable pgBackRest" id="pgbackrestEnabled"
                helper="When enabled, a pgBackRest sidecar container will be deployed alongside your PostgreSQL database." />
        </div>

        @if ($pgbackrestEnabled)
            <div class="flex flex-col gap-4 pt-4">
                {{-- Auto-Expiry Warning --}}
                <div class="p-4 rounded-lg bg-amber-900/20 border border-amber-700/50">
                    <div class="flex gap-3">
                        <svg class="h-5 w-5 text-amber-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                        <div class="text-sm">
                            <p class="font-medium text-amber-400">Auto-Expiry Behavior</p>
                            <p class="text-amber-300/80 mt-1">
                                pgBackRest automatically expires old backups after each successful backup based on the retention settings below.
                                Ensure your retention values are set appropriately to avoid unexpected data loss.
                            </p>
                        </div>
                    </div>
                </div>

                <h3>Full Backup Retention</h3>
                <div class="flex gap-4 flex-wrap">
                    <x-forms.select label="Retention Type" id="retentionFullType" class="w-48"
                        helper="'Count' retains a specific number of backups. 'Time' retains backups for a number of days.">
                        <option value="count">Count (number of backups)</option>
                        <option value="time">Time (days)</option>
                    </x-forms.select>
                    <x-forms.input type="number" min="1" max="9999999" 
                        label="{{ $retentionFullType === 'time' ? 'Retention Days' : 'Retention Count' }}"
                        id="retentionFull"
                        helper="{{ $retentionFullType === 'time' 
                            ? 'Number of days to retain full backups. At least one backup older than this period will be kept.' 
                            : 'Number of full backups to retain. When exceeded, the oldest full backup (and its dependents) will be expired.' }}" />
                </div>

                <h3>Differential Backup Retention</h3>
                <div class="flex gap-4">
                    <x-forms.input type="number" min="1" max="9999999" label="Differential Retention Count"
                        id="retentionDiff"
                        helper="Total number of differential backups to retain globally (not per full backup). When a differential expires, its incremental backups also expire. Leave high if unsure." />
                </div>

                <h3>Archive (WAL) Retention</h3>
                <p class="text-sm text-neutral-500 -mt-2">
                    Controls how long Write-Ahead Log (WAL) archives are kept. WAL is required for Point-in-Time Recovery (PITR).
                </p>
                <div class="flex gap-4 flex-wrap">
                    <x-forms.select label="Archive Retention Type" id="retentionArchiveType" class="w-48"
                        helper="Determines which backup type is used to calculate WAL retention. 'Full' is recommended.">
                        <option value="full">Full backups (recommended)</option>
                        <option value="diff">Differential backups</option>
                        <option value="incr">Incremental backups</option>
                    </x-forms.select>
                    <x-forms.input type="number" min="1" max="9999999" label="Archive Retention Count"
                        id="retentionArchive"
                        placeholder="Default: same as full retention"
                        helper="Number of backups (of the selected type) worth of WAL to retain. Leave empty to use the same value as full backup retention (recommended)." />
                </div>

                @if ($retentionArchiveType !== 'full' || $retentionArchive !== null)
                    <div class="p-3 rounded bg-yellow-900/20 border border-yellow-700/50 text-sm">
                        <p class="text-yellow-400">
                            <strong>PITR Warning:</strong> Custom archive retention settings may limit your Point-in-Time Recovery capability.
                            If WAL archives are expired before their associated backup, you won't be able to restore to points in time between backups.
                        </p>
                    </div>
                @endif

                <h3>Compression Settings</h3>
                <div class="flex gap-4">
                    <x-forms.select label="Compression Type" id="compressType"
                        helper="Compression algorithm to use for backups. lz4 is recommended for best performance.">
                        <option value="none">None</option>
                        <option value="bz2">bzip2</option>
                        <option value="gz">gzip</option>
                        <option value="lz4">lz4 (recommended)</option>
                        <option value="zst">zstd</option>
                    </x-forms.select>
                    <x-forms.input type="number" min="0" max="9" label="Compression Level"
                        id="compressLevel"
                        helper="Compression level (0-9). Higher values = better compression but slower." />
                </div>

                <h3>Logging</h3>
                <div class="w-64">
                    <x-forms.select label="Log Level" id="logLevel"
                        helper="Console log level for pgBackRest operations.">
                        <option value="off">Off</option>
                        <option value="error">Error</option>
                        <option value="warn">Warning</option>
                        <option value="info">Info</option>
                        <option value="detail">Detail</option>
                        <option value="debug">Debug</option>
                        <option value="trace">Trace</option>
                    </x-forms.select>
                </div>
            </div>
        @endif
    </form>
</div>
