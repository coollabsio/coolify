<div x-data="{
    search: @js($search),
    typeFilter: 'all',
    sortBy: 'target_asc',
    filterOpen: false,
    sortOpen: false,
    backups: @js($backups->map(fn ($backup) => [
        'id' => 'storage:'.$backup->id,
        'name' => strtolower($backup->targetName()),
        'type' => strtolower($backup->targetType()),
        'frequency' => strtolower($backup->frequency),
        'createdAt' => $backup->created_at?->timestamp ?? 0,
    ])->concat($databaseBackups->map(fn ($backup) => [
        'id' => 'database:'.$backup->id,
        'name' => strtolower($backup->database->human_name ?: $backup->database->name),
        'type' => 'database',
        'frequency' => strtolower($backup->frequency),
        'createdAt' => $backup->created_at?->timestamp ?? 0,
    ]))->values()),
    filterOptions: @js(collect([['value' => 'all', 'label' => 'All targets']])->merge(
        $backups->map(fn ($backup) => [
            'value' => strtolower($backup->targetType()),
            'label' => $backup->targetType(),
        ])->push(['value' => 'database', 'label' => 'Database'])->unique('value')->values()
    )->values()),
    sortOptions: [
        { value: 'target_asc', label: 'Target A–Z' },
        { value: 'target_desc', label: 'Target Z–A' },
        { value: 'newest', label: 'Newest first' },
        { value: 'oldest', label: 'Oldest first' },
    ],
    get filteredBackups() {
        const query = this.search.toLowerCase();
        const filtered = this.backups.filter((backup) => {
            const matchesSearch = !query || backup.name.includes(query) || backup.type.includes(query) || backup.frequency.includes(query);
            const matchesType = this.typeFilter === 'all' || backup.type === this.typeFilter;
            return matchesSearch && matchesType;
        });
        return filtered.sort((left, right) => {
            if (this.sortBy === 'target_desc') return right.name.localeCompare(left.name);
            if (this.sortBy === 'newest') return right.createdAt - left.createdAt;
            if (this.sortBy === 'oldest') return left.createdAt - right.createdAt;
            return left.name.localeCompare(right.name);
        });
    },
    isVisible(id) {
        return this.filteredBackups.some((backup) => backup.id === String(id));
    },
    backupOrder(id) {
        return this.filteredBackups.findIndex((backup) => backup.id === String(id));
    },
}">
    <x-slot:title>
        {{ data_get_str($service, 'name')->limit(10) }} > Backups | Coolify
    </x-slot>
    <livewire:project.service.heading :service="$service" :parameters="$parameters" :query="request()->query()"
        wire:key="service-heading-volume-backup-index" />

    <section class="application-settings-workspace mt-4 w-full max-w-[1180px] lg:mt-0">
        <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
            <x-service.configuration-sidebar :service="$service"
                current-route="project.service.volume-backups.index" />

            <div class="application-settings-form min-w-0 flex flex-col gap-6">
        <x-application.settings-section title="Backups"
            helper="Manage database, persistent volume, and directory backup schedules for this service.">
            @can('update', $service)
                <x-slot:actions>
                    <div x-data="{ dropdownOpen: false }">
                        <div class="relative" @click.outside="dropdownOpen = false">
                            <x-forms.button class="button-highlighted" @click="dropdownOpen = !dropdownOpen"
                                aria-haspopup="menu" x-bind:aria-expanded="dropdownOpen">
                                <x-reicon name="plus" class="size-3.5" />
                                Add backup
                                <x-reicon name="chevron-down" class="size-3 opacity-55" />
                            </x-forms.button>

                            <div x-show="dropdownOpen" x-cloak role="menu" x-transition.origin.top.left
                                class="listbox-panel left-0! right-auto! z-[90]! w-52! min-w-52! sm:left-auto! sm:right-0!">
                            <x-modal-input title="New storage backup" :wireIgnore="false">
                                <x-slot:content>
                                    <button type="button" role="menuitem" @click="dropdownOpen = false"
                                        class="listbox-option justify-start! gap-2.5!">
                                        <x-reicon name="storages" class="size-3.5" />
                                        Storage backup
                                    </button>
                                </x-slot:content>
                                <livewire:project.service.volume-backup.create :service="$service"
                                    wire:key="create-volume-backup-{{ $service->id }}" />
                            </x-modal-input>
                            @if ($databaseTargets->isNotEmpty())
                                <x-modal-input title="New database backup" :wireIgnore="false">
                                    <x-slot:content>
                                        <button type="button" role="menuitem" @click="dropdownOpen = false"
                                            class="listbox-option justify-start! gap-2.5!">
                                            <x-reicon name="database" class="size-3.5" />
                                            Database backup
                                        </button>
                                    </x-slot:content>
                                    <livewire:project.database.create-scheduled-backup :service="$service"
                                        wire:key="create-service-database-backup-{{ $service->id }}" />
                                </x-modal-input>
                            @endif
                            </div>
                        </div>
                    </div>
                </x-slot:actions>
            @endcan

            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <p class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Schedules</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums text-neutral-950 dark:text-fg">
                        {{ $backups->count() + $databaseBackups->count() }}
                    </p>
                </div>
                <div>
                    <p class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Enabled</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums text-neutral-950 dark:text-fg">
                        {{ $backups->where('enabled', true)->count() + $databaseBackups->where('enabled', true)->count() }}
                    </p>
                </div>
                <div>
                    <p class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Total executions</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums text-neutral-950 dark:text-fg">
                        {{ $backups->sum('executions_count') + $databaseBackups->sum('executions_count') }}
                    </p>
                </div>
            </div>
        </x-application.settings-section>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="relative w-full sm:max-w-sm">
                <x-reicon name="search"
                    class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                <input type="search" x-model="search" placeholder="Search backups" aria-label="Search backups"
                    class="input h-8! w-full py-0! pr-8! pl-8!" />
                <button x-cloak x-show="search" x-on:click="search = ''" type="button"
                    class="absolute top-1/2 right-2 flex size-5 -translate-y-1/2 items-center justify-center rounded text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.07] dark:hover:text-fg"
                    aria-label="Clear search">
                    <span class="text-sm leading-none">×</span>
                </button>
            </div>

            <div class="flex items-center gap-2">
                <div class="relative" x-on:click.outside="filterOpen = false">
                    <button type="button" class="button" x-on:click="filterOpen = !filterOpen; sortOpen = false">
                        <svg class="size-3.5 opacity-65" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M4 6h16M7 12h10M10 18h4" stroke="currentColor" stroke-width="1.7"
                                stroke-linecap="round" />
                        </svg>
                        Filter
                    </button>
                    <div x-cloak x-show="filterOpen" x-transition.origin.top.right
                        class="absolute top-9 right-0 z-50 min-w-48 rounded-lg border border-neutral-200 bg-white p-1 shadow-modal dark:border-white/[0.1] dark:bg-raised">
                        <template x-for="option in filterOptions" :key="option.value">
                            <button type="button"
                                class="flex h-8 w-full items-center rounded-md px-2 text-left text-[12px] text-neutral-600 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                x-on:click="typeFilter = option.value; filterOpen = false">
                                <span class="flex-1" x-text="option.label"></span>
                                <svg x-show="typeFilter === option.value" class="size-3.5 text-warning"
                                    viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                    <path d="m2.5 6.25 2.1 2.1 4.9-5" stroke="currentColor" stroke-width="1.4"
                                        stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                        </template>
                    </div>
                </div>

                <div class="relative" x-on:click.outside="sortOpen = false">
                    <button type="button" class="button" x-on:click="sortOpen = !sortOpen; filterOpen = false">
                        <svg class="size-3.5 opacity-65" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M8 5v14m0 0-3-3m3 3 3-3M16 19V5m0 0-3 3m3-3 3 3" stroke="currentColor"
                                stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        Sort
                    </button>
                    <div x-cloak x-show="sortOpen" x-transition.origin.top.right
                        class="absolute top-9 right-0 z-50 min-w-48 rounded-lg border border-neutral-200 bg-white p-1 shadow-modal dark:border-white/[0.1] dark:bg-raised">
                        <template x-for="option in sortOptions" :key="option.value">
                            <button type="button"
                                class="flex h-8 w-full items-center rounded-md px-2 text-left text-[12px] text-neutral-600 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                x-on:click="sortBy = option.value; sortOpen = false">
                                <span class="flex-1" x-text="option.label"></span>
                                <svg x-show="sortBy === option.value" class="size-3.5 text-warning"
                                    viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                    <path d="m2.5 6.25 2.1 2.1 4.9-5" stroke="currentColor" stroke-width="1.4"
                                        stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <div @class([
            'application-settings-section-body w-full',
            'is-flush' => $backups->isNotEmpty() || $databaseBackups->isNotEmpty(),
        ])>
            <div x-cloak x-show="backups.length > 0 && filteredBackups.length === 0">
                <x-empty size="sm" title="No backups found"
                    description="No scheduled backups match your search." />
            </div>

            @if ($backups->isNotEmpty() || $databaseBackups->isNotEmpty())
                <div class="data-table w-full overflow-x-auto" x-show="filteredBackups.length > 0">
                    <div class="data-table-header backup-table-grid service-backup-table-grid">
                        <span>Target</span>
                        <span>Type</span>
                        <span>Schedule</span>
                        <span>Status</span>
                        <span>S3</span>
                        <span>Last run</span>
                    </div>

                    @foreach ($databaseBackups as $databaseBackup)
                        @php
                            $latestExecution = $databaseBackup->latest_log;
                            $status = $latestExecution?->status;
                            $statusLabel = match ($status) {
                                'running' => 'In progress',
                                'success' => 'Success',
                                'failed' => 'Failed',
                                default => $databaseBackup->enabled ? 'Waiting' : 'Disabled',
                            };
                            $statusType = match ($status) {
                                'running' => 'warning',
                                'success' => 'success',
                                'failed' => 'error',
                                default => 'neutral',
                            };
                            $databaseBackupId = 'database:'.$databaseBackup->id;
                        @endphp
                        <a wire:key="database-backup-{{ $databaseBackup->uuid }}"
                            x-show="isVisible(@js($databaseBackupId))"
                            x-bind:style="{ order: backupOrder(@js($databaseBackupId)) }"
                            href="{{ route('project.service.database.backup.show', [
                                ...$parameters,
                                'stack_service_uuid' => $databaseBackup->database->uuid,
                                'backup_uuid' => $databaseBackup->uuid,
                            ]) }}"
                            {{ wireNavigate() }}
                            class="data-table-row backup-table-grid text-[13px] text-neutral-700 service-backup-table-grid dark:text-fg-dim">
                            <span class="min-w-0 truncate font-medium text-neutral-950 dark:text-fg">
                                {{ $databaseBackup->database->human_name ?: $databaseBackup->database->name }}
                            </span>
                            <span>Database</span>
                            <span>{{ $databaseBackup->frequency }}</span>
                            <span><x-status-badge :status="$statusLabel" :type="$statusType" /></span>
                            <span>
                                <x-status-badge :status="$databaseBackup->save_s3 ? ($databaseBackup->s3 ? 'Configured' : 'Unavailable') : 'Not set'"
                                    :type="$databaseBackup->save_s3 ? ($databaseBackup->s3 ? 'success' : 'error') : 'neutral'" />
                            </span>
                            <span>{{ $latestExecution?->finished_at?->diffForHumans() ?? ($status === 'running' ? 'Running now' : 'Never') }}</span>
                        </a>
                    @endforeach

                    @foreach ($backups as $backup)
                        @php
                            $latestExecution = $backup->latestExecution;
                            $status = $latestExecution?->status;
                            $statusLabel = match ($status) {
                                'running' => 'In progress',
                                'success' => 'Success',
                                'failed' => 'Failed',
                                default => $backup->enabled ? 'Waiting' : 'Disabled',
                            };
                            $statusType = match ($status) {
                                'running' => 'warning',
                                'success' => 'success',
                                'failed' => 'error',
                                default => 'neutral',
                            };
                        @endphp
                        <a wire:key="volume-backup-{{ $backup->uuid }}"
                            x-show="isVisible(@js('storage:'.$backup->id))"
                            x-bind:style="{ order: backupOrder(@js('storage:'.$backup->id)) }"
                            href="{{ route('project.service.volume-backups.show', [...$parameters, 'backup_uuid' => $backup->uuid]) }}"
                            {{ wireNavigate() }}
                            class="data-table-row backup-table-grid text-[13px] text-neutral-700 service-backup-table-grid dark:text-fg-dim">
                            <span class="min-w-0 truncate font-medium text-neutral-950 dark:text-fg"
                                title="{{ $backup->targetName() }}">
                                {{ $backup->targetName() }}
                            </span>
                            <span>{{ $backup->targetType() }}</span>
                            <span>{{ $backup->frequency }}</span>
                            <span><x-status-badge :status="$statusLabel" :type="$statusType" /></span>
                            <span title="{{ $backup->save_s3 ? ($backup->s3?->name ?? 'S3 storage unavailable') : 'S3 storage is not configured' }}">
                                <x-status-badge :status="$backup->save_s3 ? ($backup->s3 ? 'Configured' : 'Unavailable') : 'Not set'"
                                    :type="$backup->save_s3 ? ($backup->s3 ? 'success' : 'error') : 'neutral'" />
                            </span>
                            <span>
                                {{ $latestExecution?->finished_at?->diffForHumans() ?? ($status === 'running' ? 'Running now' : 'Never') }}
                            </span>
                        </a>
                    @endforeach
                </div>
            @else
                <x-empty size="sm" title="No scheduled backups"
                    description="Add a database, persistent volume, or directory backup schedule to protect service data."
                    icon-name="storages" />
            @endif
        </div>
            </div>
        </div>
    </section>
</div>
