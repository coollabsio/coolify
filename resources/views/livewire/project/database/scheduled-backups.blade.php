<div x-data="{
    search: '',
    backups: @js($database->scheduledBackups->map(fn ($backup) => [
        'name' => strtolower($database->name),
        'frequency' => strtolower($backup->frequency),
        's3_storage' => strtolower($backup->s3?->name ?? ''),
    ])->values()),
    hasMatches() {
        const query = this.search.toLowerCase();

        return this.backups.some((backup) =>
            backup.name.includes(query)
            || backup.frequency.includes(query)
            || backup.s3_storage.includes(query)
        );
    },
    matchCount() {
        const query = this.search.toLowerCase();

        return this.backups.filter((backup) =>
            backup.name.includes(query)
            || backup.frequency.includes(query)
            || backup.s3_storage.includes(query)
        ).length;
    },
}">
    @if ($database->is_migrated && blank($database->custom_type))
        <form wire:submit="setCustomType" class="grid gap-4 p-5 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
            <div>
                <x-forms.listbox id="custom_type" label="Database type" :options="[
                    ['value' => 'mysql', 'label' => 'MySQL'],
                    ['value' => 'mariadb', 'label' => 'MariaDB'],
                    ['value' => 'postgresql', 'label' => 'PostgreSQL'],
                    ['value' => 'mongodb', 'label' => 'MongoDB'],
                ]" />
                <p class="mt-2 text-xs text-neutral-500 dark:text-fg-dim">
                    Select the database engine before enabling automated backups.
                </p>
            </div>
            <x-forms.button type="submit">Set database type</x-forms.button>
        </form>
    @else
        @if ($database->scheduledBackups->isNotEmpty())
            <div class="border-b border-neutral-200 p-3 dark:border-white/[0.06]">
                <div class="relative max-w-sm">
                    <x-reicon name="search"
                        class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                    <input type="search" x-model="search"
                        class="input h-8! pl-8! text-[13px]!" placeholder="Search backup schedules…" />
                </div>
            </div>
        @endif

        @if ($database->scheduledBackups->isEmpty())
            <x-empty size="sm" title="No scheduled backups"
                description="Create a schedule to start protecting this database.">
                <x-slot:icon>
                    <x-reicon name="storages" class="size-5" />
                </x-slot:icon>
            </x-empty>
        @else
            <div x-cloak x-show="search === '' || hasMatches()" class="data-table">
                <div class="data-table-header scheduled-backups-table-grid">
                    <span>Schedule</span>
                    <span>Latest run</span>
                    <span>S3 storage</span>
                    <span>Executions</span>
                    <span class="text-right">Action</span>
                </div>
                @foreach ($database->scheduledBackups as $backup)
                    @php
                        $latestStatus = data_get($backup->latest_log, 'status');
                        [$statusLabel, $statusType] = match ($latestStatus) {
                            'success' => ['Success', 'success'],
                            'running' => ['In progress', 'warning'],
                            'failed' => ['Failed', 'error'],
                            default => ['Never run', 'neutral'],
                        };
                        $backupRoute = $type === 'database'
                            ? route('project.database.backup.execution', [...$parameters, 'backup_uuid' => $backup->uuid])
                            : route('project.service.database.backup.show', [...$parameters, 'backup_uuid' => $backup->uuid]);
                    @endphp
                    <div x-show="search === ''
                        || @js(strtolower($database->name)).includes(search.toLowerCase())
                        || @js(strtolower($backup->frequency)).includes(search.toLowerCase())
                        || @js(strtolower($backup->s3?->name ?? '')).includes(search.toLowerCase())"
                        class="data-table-row scheduled-backups-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.06]">
                        <div class="min-w-0">
                            <a class="block truncate text-[12px] font-semibold text-black dark:text-fg"
                                {{ wireNavigate() }} href="{{ $backupRoute }}">
                                {{ $backup->frequency }}
                            </a>
                        </div>
                        <div class="flex items-center gap-2">
                            <x-status-badge :status="$statusLabel" :type="$statusType" />
                            @if ($latestStatus === 'running')
                                <x-loading />
                            @endif
                        </div>
                        <div class="truncate text-[11px] text-neutral-600 dark:text-fg-dim">
                            {{ $backup->save_s3 ? ($backup->s3?->name ?? 'Unavailable') : 'Local only' }}
                        </div>
                        <div class="text-[11px] text-neutral-600 dark:text-fg-dim">
                            {{ $backup->executions()->count() }}
                        </div>
                        <div class="flex justify-end">
                            <a class="button" {{ wireNavigate() }} href="{{ $backupRoute }}">Manage</a>
                        </div>
                    </div>
                @endforeach
                <div
                    class="flex min-h-11 items-center border-t border-neutral-200 px-4 text-[11px] text-neutral-500 dark:border-white/[0.08] dark:text-fg-faint">
                    <span
                        x-text="`${search === '' ? backups.length : matchCount()} ${(search === '' ? backups.length : matchCount()) === 1 ? 'schedule' : 'schedules'}`"></span>
                </div>
            </div>
        @endif

        <div x-cloak x-show="search !== '' && backups.length > 0 && !hasMatches()"
            class="border-t border-neutral-200 dark:border-white/[0.06]">
            <x-empty size="sm" title="No matching backup schedules"
                description="Try another database name, frequency, or storage name." />
        </div>
    @endif
</div>
