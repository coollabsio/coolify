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

    @if ($selectedDatabaseBackup || $selectedVolumeBackup)
        @php
            $selectedSchedule = $selectedDatabaseBackup ?: $selectedVolumeBackup;
        @endphp
        <x-modal-input :title="'Edit backup schedule'" wireOpen="scheduleModalOpen" :wireIgnore="false" isLarge
            canGate="update" :canResource="$service">
            <x-slot:content><span></span></x-slot:content>

            <div x-data="{ activeSection: 'general' }" class="flex min-w-0 flex-col gap-6">
                <div>
                    <h2 class="text-base font-semibold text-neutral-950 dark:text-fg">
                        {{ $selectedDatabaseBackup
                            ? ($selectedDatabaseBackup->database->human_name ?: $selectedDatabaseBackup->database->name)
                            : $selectedVolumeBackup->targetName() }}
                    </h2>
                    <p class="mt-1 text-xs text-neutral-500 dark:text-fg-dim">{{ $selectedSchedule->frequency }} schedule</p>
                </div>

                <x-backup-tabs context="service-schedule" :parameters="$parameters" section="general" />

                @foreach (['general', 's3', 'retention', 'danger'] as $modalSection)
                    <div x-show="activeSection === '{{ $modalSection }}'" x-cloak>
                        @if ($selectedDatabaseBackup)
                            <livewire:project.database.backup-edit :backup="$selectedDatabaseBackup"
                                :available-s3-storages="$s3s" :status="data_get($selectedDatabaseBackup->database, 'status')"
                                :section="$modalSection"
                                wire:key="service-database-backup-modal-{{ $selectedDatabaseBackup->uuid }}-{{ $modalSection }}" />
                        @else
                            <livewire:project.shared.storages.volume-backups :storage="$selectedVolumeBackup->backupable"
                                :resource="$service" :section="$modalSection"
                                wire:key="service-volume-backup-modal-{{ $selectedVolumeBackup->uuid }}-{{ $modalSection }}" />
                        @endif
                    </div>
                @endforeach
            </div>
        </x-modal-input>
    @endif

    <section class="application-settings-workspace mt-4 w-full max-w-none lg:mt-0">
        <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
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
                <x-table.dropdown panel-class="w-48!">
                    <x-slot:trigger><button type="button" class="button" aria-haspopup="listbox" :aria-expanded="open">
                        <svg class="size-3.5 opacity-65" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M4 6h16M7 12h10M10 18h4" stroke="currentColor" stroke-width="1.7"
                                stroke-linecap="round" />
                        </svg>
                        Filter
                    </button></x-slot:trigger>
                        <template x-for="option in filterOptions" :key="option.value">
                            <button type="button"
                                class="flex h-8 w-full items-center rounded-md px-2 text-left text-[12px] text-neutral-600 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                x-on:click="typeFilter = option.value; close()">
                                <span class="flex-1" x-text="option.label"></span>
                                <svg x-show="typeFilter === option.value" class="size-3.5 text-warning"
                                    viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                    <path d="m2.5 6.25 2.1 2.1 4.9-5" stroke="currentColor" stroke-width="1.4"
                                        stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                        </template>
                </x-table.dropdown>

                <x-table.dropdown panel-class="w-48!">
                    <x-slot:trigger><button type="button" class="button" aria-haspopup="listbox" :aria-expanded="open">
                        <svg class="size-3.5 opacity-65" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M8 5v14m0 0-3-3m3 3 3-3M16 19V5m0 0-3 3m3-3 3 3" stroke="currentColor"
                                stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        Sort
                    </button></x-slot:trigger>
                        <template x-for="option in sortOptions" :key="option.value">
                            <button type="button"
                                class="flex h-8 w-full items-center rounded-md px-2 text-left text-[12px] text-neutral-600 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                x-on:click="sortBy = option.value; close()">
                                <span class="flex-1" x-text="option.label"></span>
                                <svg x-show="sortBy === option.value" class="size-3.5 text-warning"
                                    viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                    <path d="m2.5 6.25 2.1 2.1 4.9-5" stroke="currentColor" stroke-width="1.4"
                                        stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                        </template>
                </x-table.dropdown>
            </div>
        </div>

        <div @class([
            'application-settings-section-body relative w-full',
            'is-flush' => $backups->isNotEmpty() || $databaseBackups->isNotEmpty(),
        ])>
            <x-table.loading target="openSchedule" text="Loading schedule..." />

            <div x-cloak x-show="backups.length > 0 && filteredBackups.length === 0">
                <x-empty size="sm" title="No backups found"
                    description="No scheduled backups match your search." />
            </div>

            @if ($backups->isNotEmpty() || $databaseBackups->isNotEmpty())
                <div class="data-table w-full overflow-x-auto" x-show="filteredBackups.length > 0">
                    <div class="min-w-[59rem]">
                    <div class="data-table-header backup-table-grid service-backup-table-grid">
                        <span>Target</span>
                        <span>Type</span>
                        <span>Schedule</span>
                        <span>Status</span>
                        <span>S3</span>
                        <span>Last run</span>
                        <span class="text-right">Actions</span>
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
                        <div wire:key="database-backup-{{ $databaseBackup->uuid }}"
                            x-show="isVisible(@js($databaseBackupId))"
                            x-bind:style="{ order: backupOrder(@js($databaseBackupId)) }"
                            wire:click="openSchedule('{{ $databaseBackup->uuid }}')"
                            wire:keydown.enter="openSchedule('{{ $databaseBackup->uuid }}')" role="button" tabindex="0"
                            class="data-table-row backup-table-grid cursor-pointer text-left text-[13px] text-neutral-700 service-backup-table-grid dark:text-fg-dim">
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
                            <span class="flex justify-end">
                                <x-forms.button type="button" canGate="update" :canResource="$service"
                                    wire:click.stop="backupNow('database', '{{ $databaseBackup->uuid }}')"
                                    wire:target="backupNow('database', '{{ $databaseBackup->uuid }}')">Back up now</x-forms.button>
                            </span>
                        </div>
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
                        <div wire:key="volume-backup-{{ $backup->uuid }}"
                            x-show="isVisible(@js('storage:'.$backup->id))"
                            x-bind:style="{ order: backupOrder(@js('storage:'.$backup->id)) }"
                            wire:click="openSchedule('{{ $backup->uuid }}')"
                            wire:keydown.enter="openSchedule('{{ $backup->uuid }}')" role="button" tabindex="0"
                            class="data-table-row backup-table-grid cursor-pointer text-left text-[13px] text-neutral-700 service-backup-table-grid dark:text-fg-dim">
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
                            <span class="flex justify-end">
                                <x-forms.button type="button" canGate="update" :canResource="$service"
                                    wire:click.stop="backupNow('storage', '{{ $backup->uuid }}')"
                                    wire:target="backupNow('storage', '{{ $backup->uuid }}')">Back up now</x-forms.button>
                            </span>
                        </div>
                    @endforeach
                    </div>
                </div>
            @else
                <x-empty size="sm" title="No scheduled backups"
                    description="Add a database, persistent volume, or directory backup schedule to protect service data."
                    icon-name="storages" />
            @endif
        </div>
        <livewire:project.service.backup-executions :service="$service"
            wire:key="service-backup-executions-{{ $service->id }}" />
            </div>
        </div>
    </section>
</div>
