<div x-data="{
    search: @js($search),
    typeFilter: 'all',
    sortBy: 'target_asc',
    backups: @js($backups->map(fn ($backup) => [
        'id' => (string) $backup->id,
        'name' => strtolower($backup->targetName()),
        'type' => strtolower($backup->targetType()),
        'frequency' => strtolower($backup->frequency),
        'createdAt' => $backup->created_at?->timestamp ?? 0,
    ])->values()),
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
        {{ data_get_str($application, 'name')->limit(10) }} > Backups | Coolify
    </x-slot>
    <livewire:project.shared.configuration-checker :resource="$application" />
    <livewire:project.application.heading :application="$application" />

    <div class="application-settings-form flex flex-col gap-6">
        <x-application.settings-section title="Storage backups"
            helper="Schedule backups for persistent volumes and directory mounts attached to this application.">
            @can('update', $application)
                <x-slot:actions>
                    <x-modal-input title="New scheduled backup" :wireIgnore="false">
                        <x-slot:content>
                            <button type="button"
                                class="button bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!">
                                <x-reicon name="plus" class="size-3.5" />
                                Add
                            </button>
                        </x-slot:content>
                        <livewire:project.application.backup.create :application="$application"
                            wire:key="create-volume-backup-{{ $application->id }}" />
                    </x-modal-input>
                </x-slot:actions>
            @endcan

            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <p class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Schedules</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums text-neutral-950 dark:text-fg">
                        {{ $backups->count() }}
                    </p>
                </div>
                <div>
                    <p class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Enabled</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums text-neutral-950 dark:text-fg">
                        {{ $backups->where('enabled', true)->count() }}
                    </p>
                </div>
                <div>
                    <p class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Total executions</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums text-neutral-950 dark:text-fg">
                        {{ $backups->sum('executions_count') }}
                    </p>
                </div>
            </div>
        </x-application.settings-section>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="relative min-w-0 max-w-md flex-1">
                <input type="search" x-model="search" placeholder="Search backups" aria-label="Search backups"
                    class="input w-full pl-8!" />
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2.5">
                    <x-reicon name="search" class="size-3.5 text-neutral-400 dark:text-fg-faint" />
                </div>
            </div>
            <div class="flex items-center gap-2">
                <div class="w-40">
                    <x-forms.listbox id="backup-type-filter" :wire="false" x-model="typeFilter"
                        :value="'all'" :options="collect([['value' => 'all', 'label' => 'All targets']])->merge(
                            $backups->map(fn ($backup) => [
                                'value' => strtolower($backup->targetType()),
                                'label' => $backup->targetType(),
                            ])->unique('value')->values()
                        )->all()" />
                </div>
                <div class="w-40">
                    <x-forms.listbox id="backup-sort" :wire="false" x-model="sortBy"
                        :value="'target_asc'" :options="[
                            ['value' => 'target_asc', 'label' => 'Target A–Z'],
                            ['value' => 'target_desc', 'label' => 'Target Z–A'],
                            ['value' => 'newest', 'label' => 'Newest first'],
                            ['value' => 'oldest', 'label' => 'Oldest first'],
                        ]" />
                </div>
            </div>
        </div>

        <div class="application-settings-section-body is-flush w-full">
            <div x-cloak x-show="backups.length > 0 && filteredBackups.length === 0">
                <x-empty size="sm" title="No backups found"
                    description="No scheduled backups match your search." />
            </div>

            @if ($backups->isNotEmpty())
                <div class="data-table flex w-full flex-col" x-show="filteredBackups.length > 0">
                    <div class="data-table-header backup-table-grid">
                        <span>Target</span>
                        <span>Type</span>
                        <span>Schedule</span>
                        <span>Status</span>
                        <span>Last run</span>
                        <span class="text-right">Executions</span>
                        <span></span>
                    </div>

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
                            x-show="isVisible(@js((string) $backup->id))"
                            x-bind:style="{ order: backupOrder(@js((string) $backup->id)) }"
                            href="{{ route('project.application.backup.show', [...$parameters, 'backup_uuid' => $backup->uuid]) }}"
                            {{ wireNavigate() }}
                            class="data-table-row backup-table-grid text-[13px] text-neutral-700 dark:text-fg-dim">
                            <span class="min-w-0 truncate font-medium text-neutral-950 dark:text-fg"
                                title="{{ $backup->targetName() }}">
                                {{ $backup->targetName() }}
                            </span>
                            <span>{{ $backup->targetType() }}</span>
                            <span class="font-mono text-xs">{{ $backup->frequency }}</span>
                            <span><x-status-badge :status="$statusLabel" :type="$statusType" /></span>
                            <span>
                                {{ $latestExecution?->finished_at?->diffForHumans() ?? ($status === 'running' ? 'Running now' : 'Never') }}
                            </span>
                            <span class="text-right tabular-nums text-neutral-950 dark:text-fg">
                                {{ $backup->executions_count }}
                            </span>
                            <span class="flex justify-end text-neutral-400 dark:text-fg-faint">
                                <x-reicon name="arrow-right" class="size-3.5" />
                            </span>
                        </a>
                    @endforeach
                </div>
            @else
                <x-empty size="sm" title="No scheduled backups"
                    description="Add a persistent volume or directory backup schedule to protect application data.">
                    <x-slot:icon>
                        <x-reicon name="storages" class="size-8" />
                    </x-slot:icon>
                </x-empty>
            @endif
        </div>
    </div>
</div>
