<div>
    <form wire:submit="submit" class="flex flex-col gap-4">
        <div class="flex items-center gap-2">
            <h2>pgBackRest Backup</h2>
            @if ($pgbackrestEnabled)
                <x-forms.button type="submit">Save</x-forms.button>
            @endif
        </div>

        <div class="pb-4">
            <p class="text-sm text-neutral-500">
                pgBackRest is an advanced backup and restore solution for PostgreSQL databases.
                It provides features like incremental backups, parallel backup/restore, and backup verification.
            </p>
        </div>

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

                <h3 class="pt-4">Stanza Management</h3>
                <div class="flex flex-col gap-2">
                    <p class="text-sm text-neutral-500">
                        A stanza is a pgBackRest configuration section for your database. 
                        It needs to be initialized once after enabling pgBackRest.
                    </p>
                    <div class="flex gap-2">
                        <x-forms.button wire:click="initializeStanza" type="button">
                            Initialize Stanza
                        </x-forms.button>
                        <x-forms.button wire:click="refreshBackups" type="button">
                            Refresh Backup List
                        </x-forms.button>
                    </div>
                </div>

                @if (count($backups) > 0)
                    <h3 class="pt-4">Available Backups</h3>
                    <div class="overflow-x-auto">
                        <table class="table-auto w-full text-sm">
                            <thead>
                                <tr class="border-b border-neutral-700">
                                    <th class="px-4 py-2 text-left">Label</th>
                                    <th class="px-4 py-2 text-left">Type</th>
                                    <th class="px-4 py-2 text-left">Size</th>
                                    <th class="px-4 py-2 text-left">Started</th>
                                    <th class="px-4 py-2 text-left">Completed</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($backups as $backup)
                                    <tr class="border-b border-neutral-800">
                                        <td class="px-4 py-2 font-mono text-xs">{{ $backup['label'] }}</td>
                                        <td class="px-4 py-2">
                                            <span class="px-2 py-1 rounded text-xs
                                                @if ($backup['type'] === 'full') bg-green-600
                                                @elseif ($backup['type'] === 'diff') bg-blue-600
                                                @else bg-yellow-600 @endif">
                                                {{ $backup['type'] }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-2">{{ $backup['size_formatted'] ?? formatBytes($backup['size'] ?? 0) }}</td>
                                        <td class="px-4 py-2">
                                            @if ($backup['timestamp_start'])
                                                {{ \Carbon\Carbon::createFromTimestamp($backup['timestamp_start'])->format('Y-m-d H:i:s') }}
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td class="px-4 py-2">
                                            @if ($backup['timestamp_stop'])
                                                {{ \Carbon\Carbon::createFromTimestamp($backup['timestamp_stop'])->format('Y-m-d H:i:s') }}
                                            @else
                                                -
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @elseif ($pgbackrestEnabled)
                    <div class="pt-4 text-neutral-500">
                        No backups found. Initialize the stanza and run a backup to see available backups here.
                    </div>
                @endif
            </div>
        @endif
    </form>
</div>
