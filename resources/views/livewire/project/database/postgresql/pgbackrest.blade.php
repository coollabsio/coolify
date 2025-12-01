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
                <h3>Retention Settings</h3>
                <div class="flex gap-4">
                    <x-forms.input type="number" min="1" max="100" label="Full Backup Retention"
                        id="retentionFull"
                        helper="Number of full backups to retain. Older full backups and their dependent differential backups will be removed." />
                    <x-forms.input type="number" min="1" max="100" label="Differential Backup Retention"
                        id="retentionDiff"
                        helper="Number of differential backups to retain for each full backup." />
                </div>

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
