<div>
    <x-slot:title>Deployments | Coolify</x-slot>
    <h1>Deployments</h1>
    <div class="subtitle">All application deployments for the current team.</div>

    <div class="flex flex-col gap-4 pb-10"
        @if ($deployments->currentPage() === 1) wire:poll.5000ms="reloadDeployments" @endif>
        <div class="flex flex-wrap items-end gap-3">
            @if ($showProjectFilter)
                <div class="min-w-[10rem]">
                    <label class="block text-sm font-medium text-gray-700 dark:text-neutral-300 mb-1"
                        for="filter-project">Project</label>
                    <select id="filter-project" wire:model.live="filterProject"
                        class="w-full rounded-md border-neutral-300 dark:border-coolgray-200 dark:bg-coolgray-100 shadow-xs text-sm">
                        <option value="">All projects</option>
                        @foreach ($projects as $project)
                            <option value="{{ $project->uuid }}">{{ $project->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            @if ($showServerFilter)
                <div class="min-w-[10rem]">
                    <label class="block text-sm font-medium text-gray-700 dark:text-neutral-300 mb-1"
                        for="filter-server">Server</label>
                    <select id="filter-server" wire:model.live="filterServer"
                        class="w-full rounded-md border-neutral-300 dark:border-coolgray-200 dark:bg-coolgray-100 shadow-xs text-sm">
                        <option value="">All servers</option>
                        @foreach ($teamServers as $server)
                            <option value="{{ $server->id }}">{{ $server->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            @if ($showSourceFilter)
                <div class="min-w-[10rem]">
                    <label class="block text-sm font-medium text-gray-700 dark:text-neutral-300 mb-1"
                        for="filter-source">Source (Git)</label>
                    <select id="filter-source" wire:model.live="filterSource"
                        class="w-full rounded-md border-neutral-300 dark:border-coolgray-200 dark:bg-coolgray-100 shadow-xs text-sm">
                        <option value="">All sources</option>
                        @if ($hasNullGitType)
                            <option value="__none__">Not set</option>
                        @endif
                        @foreach ($gitTypes as $type)
                            <option value="{{ $type }}">{{ str($type)->headline() }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            @if ($showStatusFilter)
                <div class="min-w-[10rem]">
                    <label class="block text-sm font-medium text-gray-700 dark:text-neutral-300 mb-1"
                        for="filter-status">Status</label>
                    <select id="filter-status" wire:model.live="filterStatus"
                        class="w-full rounded-md border-neutral-300 dark:border-coolgray-200 dark:bg-coolgray-100 shadow-xs text-sm">
                        <option value="">All statuses</option>
                        @foreach ($statusValues as $status)
                            <option value="{{ $status }}">{{ str($status)->headline() }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            @if ($filterProject !== '' || $filterServer !== '' || $filterSource !== '' || $filterStatus !== '')
                <x-forms.button type="button" wire:click="clearFilters">Clear filters</x-forms.button>
            @endif
        </div>

        <div class="flex items-center gap-2">
            <h2 class="text-lg">History <span class="text-xs text-gray-600 dark:text-gray-400">({{ $deployments->total() }})</span>
            </h2>
        </div>

        @forelse ($deployments as $deployment)
            @php
                $application = $deployment->application;
                $project = $application?->environment?->project;
                $serverForTz = $application?->destination?->server;
            @endphp
            <div @class([
                'p-2 border-l-2 bg-white dark:bg-coolgray-100',
                'border-blue-500/50 border-dashed' => data_get($deployment, 'status') === 'in_progress',
                'border-purple-500/50 border-dashed' => data_get($deployment, 'status') === 'queued',
                'border-white border-dashed' => data_get($deployment, 'status') === 'cancelled-by-user',
                'border-error' => data_get($deployment, 'status') === 'failed',
                'border-success' => data_get($deployment, 'status') === 'finished',
            ])>
                <a href="{{ $this->deploymentPath($deployment) }}" {{ wireNavigate() }} class="block">
                    <div class="flex flex-col">
                        <div class="flex flex-wrap items-center gap-2 mb-1">
                            <span @class([
                                'px-3 py-1 rounded-md text-xs font-medium shadow-xs',
                                'bg-blue-100/80 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300' =>
                                    data_get($deployment, 'status') === 'in_progress',
                                'bg-purple-100/80 text-purple-700 dark:bg-purple-500/20 dark:text-purple-300' =>
                                    data_get($deployment, 'status') === 'queued',
                                'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200' =>
                                    data_get($deployment, 'status') === 'failed',
                                'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200' =>
                                    data_get($deployment, 'status') === 'finished',
                                'bg-gray-100 text-gray-700 dark:bg-gray-600/30 dark:text-gray-300' =>
                                    data_get($deployment, 'status') === 'cancelled-by-user',
                            ])>
                                @php
                                    $statusText = match (data_get($deployment, 'status')) {
                                        'finished' => 'Success',
                                        'in_progress' => 'In Progress',
                                        'cancelled-by-user' => 'Cancelled',
                                        'queued' => 'Queued',
                                        default => ucfirst(str_replace('-', ' ', (string) data_get($deployment, 'status'))),
                                    };
                                @endphp
                                {{ $statusText }}
                            </span>
                            @if ($project)
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $project->name }}</span>
                                <span class="text-xs text-gray-400 dark:text-gray-500">·</span>
                            @endif
                            <span class="text-sm font-medium text-gray-900 dark:text-neutral-100">
                                {{ $deployment->application_name ?? $application?->name ?? 'Application' }}
                            </span>
                            @if ($deployment->server_name)
                                <span class="text-xs text-gray-500 dark:text-gray-400">· {{ $deployment->server_name }}</span>
                            @endif
                            @if (filled($deployment->git_type))
                                <span
                                    class="text-xs bg-gray-200/70 dark:bg-gray-600/20 px-2 py-0.5 rounded-md text-gray-800 dark:text-gray-100 border border-gray-400/30">
                                    {{ str($deployment->git_type)->headline() }}
                                </span>
                            @endif
                        </div>

                        @if (data_get($deployment, 'status') !== 'queued' && $serverForTz)
                            <div class="text-gray-600 dark:text-gray-400 text-sm">
                                Started:
                                {{ formatDateInServerTimezone(data_get($deployment, 'created_at'), $serverForTz) }}
                                @if ($deployment->status !== 'in_progress' && $deployment->status !== 'cancelled-by-user' && $deployment->finished_at)
                                    <br>Ended:
                                    {{ formatDateInServerTimezone(data_get($deployment, 'finished_at'), $serverForTz) }}
                                    <br>Duration:
                                    {{ calculateDuration(data_get($deployment, 'created_at'), data_get($deployment, 'finished_at')) }}
                                @elseif($deployment->status === 'in_progress')
                                    <br>Running for:
                                    {{ calculateDuration(data_get($deployment, 'created_at'), now()) }}
                                @endif
                            </div>
                        @endif

                        @if (data_get($deployment, 'commit') && $application)
                            <div class="text-gray-600 dark:text-gray-400 text-sm mt-2">
                                <span class="font-medium">Commit:</span>
                                <a href="{{ $application->gitCommitLink(data_get($deployment, 'commit')) }}" target="_blank"
                                    class="underline" onclick="event.stopPropagation()">
                                    {{ substr(data_get($deployment, 'commit'), 0, 7) }}
                                </a>
                            </div>
                        @endif
                    </div>
                </a>
            </div>
        @empty
            <div>No deployments found.</div>
        @endforelse

        <div class="pt-2">
            {{ $deployments->links() }}
        </div>
    </div>
</div>
