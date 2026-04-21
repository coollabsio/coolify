<div>
    <x-slot:title>
        Deployments | Coolify
    </x-slot>

    <div class="flex items-center gap-2">
        <h1>Deployments</h1>
        <span class="text-xs">({{ $deployments->count() }})</span>
    </div>
    <div class="subtitle">Track recent and historical deployments across the current team.</div>

    @php
        $filterColumns = 2 + ($availableServers->count() > 1 ? 1 : 0) + ($availableSources->count() > 1 ? 1 : 0);
        $filterGridClass = match ($filterColumns) {
            2 => 'xl:grid-cols-2',
            3 => 'xl:grid-cols-3',
            default => 'xl:grid-cols-4',
        };
    @endphp

    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 mb-6 {{ $filterGridClass }}">
        <div>
            <label class="label">Project</label>
            <select wire:model.live="project" class="w-full rounded border border-neutral-300 bg-white px-3 py-2 dark:border-coolgray-200 dark:bg-coolgray-100">
                <option value="">All projects</option>
                @foreach ($availableProjects as $projectName)
                    <option value="{{ $projectName }}">{{ $projectName }}</option>
                @endforeach
            </select>
        </div>
        @if ($availableServers->count() > 1)
            <div>
                <label class="label">Server</label>
                <select wire:model.live="server" class="w-full rounded border border-neutral-300 bg-white px-3 py-2 dark:border-coolgray-200 dark:bg-coolgray-100">
                    <option value="">All servers</option>
                    @foreach ($availableServers as $serverName)
                        <option value="{{ $serverName }}">{{ $serverName }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        @if ($availableSources->count() > 1)
            <div>
                <label class="label">Source</label>
                <select wire:model.live="source" class="w-full rounded border border-neutral-300 bg-white px-3 py-2 dark:border-coolgray-200 dark:bg-coolgray-100">
                    <option value="">All sources</option>
                    @foreach ($availableSources as $sourceName)
                        <option value="{{ $sourceName }}">{{ str($sourceName)->headline() }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div>
            <label class="label">Status</label>
            <select wire:model.live="status" class="w-full rounded border border-neutral-300 bg-white px-3 py-2 dark:border-coolgray-200 dark:bg-coolgray-100">
                <option value="">All statuses</option>
                @foreach ($availableStatuses as $statusName)
                    <option value="{{ $statusName }}">{{ str($statusName)->headline() }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="flex flex-col gap-3" wire:poll.5000ms>
        @forelse ($deployments as $deployment)
            <a href="{{ data_get($deployment, 'deployment_url') ?: '#' }}" {{ wireNavigate() }} class="block">
                <div @class([
                    'p-4 border-l-2 bg-white dark:bg-coolgray-100 rounded-r-md',
                    'border-purple-500/50 border-dashed' => data_get($deployment, 'status') === 'queued',
                    'border-blue-500/50 border-dashed' => data_get($deployment, 'status') === 'in_progress',
                    'border-success' => data_get($deployment, 'status') === 'finished',
                    'border-error' => data_get($deployment, 'status') === 'failed',
                    'border-white border-dashed' => data_get($deployment, 'status') === 'cancelled-by-user',
                ])>
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="box-title">{{ data_get($deployment, 'application_name') }}</div>
                            <div class="box-description">
                                {{ data_get($deployment, 'application.environment.project.name') }}
                            </div>
                        </div>
                        <span class="text-xs px-2 py-1 rounded bg-neutral-100 dark:bg-coolgray-200">
                            {{ str(data_get($deployment, 'status'))->headline() }}
                        </span>
                    </div>
                    <div class="mt-3 text-sm text-neutral-600 dark:text-neutral-400 grid gap-1 md:grid-cols-2 xl:grid-cols-4">
                        <div>Server: {{ data_get($deployment, 'server_name') ?: 'Unknown' }}</div>
                        <div>Source: {{ str(data_get($deployment, 'git_type') ?: 'unknown')->headline() }}</div>
                        <div>Created: {{ data_get($deployment, 'created_at') }}</div>
                        <div>Commit: {{ str(data_get($deployment, 'commit') ?: 'HEAD')->limit(12) }}</div>
                    </div>
                    @if (data_get($deployment, 'commit_message'))
                        <div class="mt-2 text-sm text-neutral-600 dark:text-neutral-400">
                            {{ data_get($deployment, 'commit_message') }}
                        </div>
                    @endif
                </div>
            </a>
        @empty
            <div>No deployments found.</div>
        @endforelse
    </div>
</div>
