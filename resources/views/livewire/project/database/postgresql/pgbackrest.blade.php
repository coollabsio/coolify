<div>
    <form wire:submit="submit" class="flex flex-col gap-4">
        <div class="flex items-center gap-2">
            <h2>pgBackRest</h2>
            <x-forms.button type="submit">Save</x-forms.button>
        </div>

        <div class="pb-4">
            <p class="text-sm text-neutral-500">
                pgBackRest is an advanced backup and restore solution for PostgreSQL databases.
                It provides features like incremental backups, parallel backup/restore, and backup verification.
            </p>
            <p class="text-sm text-neutral-500 mt-2">
                pgBackRest is automatically enabled when any scheduled backup is configured to use it.
                Storage and retention settings are configured per backup schedule.
            </p>
        </div>

        @if ($database->isPgbackrestEnabled())
            <div class="p-3 rounded bg-green-900/20 border border-green-700/50 text-sm">
                <p class="text-green-400">
                    <strong>Status:</strong> pgBackRest is enabled. One or more scheduled backups are using pgBackRest.
                </p>
            </div>
        @else
            <div class="p-3 rounded bg-neutral-800 border border-neutral-700 text-sm">
                <p class="text-neutral-400">
                    <strong>Status:</strong> pgBackRest is not active. Enable it on a scheduled backup to start using pgBackRest.
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
    </form>
</div>
