<div x-data="{
    search: @js($search),
    backups: @js($backups->map(fn ($backup) => [
        'name' => strtolower($backup->targetName()),
        'type' => strtolower($backup->targetType()),
        'frequency' => strtolower($backup->frequency),
    ])->values()),
    hasMatches() {
        const query = this.search.toLowerCase();

        return this.backups.some((backup) => backup.name.includes(query) || backup.type.includes(query) || backup.frequency.includes(query));
    },
}">
    <x-slot:title>
        {{ data_get_str($application, 'name')->limit(10) }} > Backups | Coolify
    </x-slot>
    <h1>Backups</h1>
    <livewire:project.shared.configuration-checker :resource="$application" />
    <livewire:project.application.heading :application="$application" />

    <div class="flex items-center gap-2 pb-4">
        <h2>Scheduled Backups</h2>
        @can('update', $application)
            <x-modal-input buttonTitle="+ Add" title="New Scheduled Backup" :wireIgnore="false">
                <livewire:project.application.backup.create :application="$application"
                    wire:key="create-volume-backup-{{ $application->id }}" />
            </x-modal-input>
        @endcan
    </div>

    <div class="max-w-md pb-4">
        <x-forms.input id="null" type="search" x-model="search" placeholder="Search by target name, type, or frequency..." />
    </div>

    <div class="flex flex-col gap-2">
        <div x-cloak x-show="search !== '' && backups.length > 0 && !hasMatches()">
            No scheduled backups match your search.
        </div>
        @forelse ($backups as $backup)
            @php($latestExecution = $backup->latestExecution)
            <a x-show="search === '' || @js(strtolower($backup->targetName())).includes(search.toLowerCase()) || @js(strtolower($backup->targetType())).includes(search.toLowerCase()) || @js(strtolower($backup->frequency)).includes(search.toLowerCase())" @class([
                'flex flex-col border-l-2 transition-colors p-4 cursor-pointer bg-white hover:bg-gray-100 dark:bg-coolgray-100 dark:hover:bg-coolgray-200 text-black dark:text-white',
                'border-blue-500/50 border-dashed' => $latestExecution?->status === 'running',
                'border-error' => $latestExecution?->status === 'failed',
                'border-success' => $latestExecution?->status === 'success',
                'border-gray-200 dark:border-coolgray-300' => !$latestExecution,
            ]) {{ wireNavigate() }}
                href="{{ route('project.application.backup.show', [...$parameters, 'backup_uuid' => $backup->uuid]) }}">
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    @if ($latestExecution)
                        <span @class([
                            'px-3 py-1 rounded-md text-xs font-medium tracking-wide shadow-xs',
                            'bg-blue-100/80 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300' => $latestExecution->status === 'running',
                            'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200' => $latestExecution->status === 'failed',
                            'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200' => $latestExecution->status === 'success',
                        ])>
                            {{ $latestExecution->status === 'running' ? 'In Progress' : ucfirst($latestExecution->status) }}
                        </span>
                    @else
                        <span class="px-3 py-1 text-xs font-medium tracking-wide text-gray-800 bg-gray-100 rounded-md shadow-xs dark:bg-neutral-800 dark:text-gray-200">
                            No executions yet
                        </span>
                    @endif
                    <h3 class="font-semibold">{{ $backup->frequency }}</h3>
                    @if (!$backup->enabled)
                        <span class="text-xs text-neutral-500">Disabled</span>
                    @endif
                </div>
                <div class="text-sm text-gray-600 dark:text-gray-400">
                    {{ $backup->targetType() }}: {{ $backup->targetName() }}
                    @if ($latestExecution?->finished_at)
                        • Last run {{ $latestExecution->finished_at->diffForHumans() }}
                    @else
                        • Last run: Never
                    @endif
                    • Total executions: {{ $backup->executions_count }}
                    @if ($backup->save_s3)
                        • S3: Enabled
                    @endif
                    @if (($latestExecution?->size ?? 0) > 0)
                        • Size: {{ formatBytes($latestExecution->size) }}
                    @endif
                </div>
            </a>
        @empty
            <div>No scheduled backups configured.</div>
        @endforelse
    </div>
</div>
