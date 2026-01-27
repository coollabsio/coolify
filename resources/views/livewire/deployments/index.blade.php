<div>
    <h1>Deployments</h1>
    <div class="subtitle">All deployments across your projects.</div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-end gap-3 mb-6" wire:poll.5000ms='reloadDeployments'>
        @if (count($availableStatuses) > 1)
            <div class="w-full sm:w-auto">
                <x-forms.select wire:model.live="status" label="Status" id="status">
                    <option value="">All Statuses</option>
                    @foreach ($availableStatuses as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-forms.select>
            </div>
        @endif

        @if (count($availableProjects) > 1)
            <div class="w-full sm:w-auto">
                <x-forms.select wire:model.live="project" label="Project" id="project">
                    <option value="">All Projects</option>
                    @foreach ($availableProjects as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-forms.select>
            </div>
        @endif

        @if (count($availableServers) > 1)
            <div class="w-full sm:w-auto">
                <x-forms.select wire:model.live="server" label="Server" id="server">
                    <option value="">All Servers</option>
                    @foreach ($availableServers as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-forms.select>
            </div>
        @endif

        @if (count($availableSources) > 1)
            <div class="w-full sm:w-auto">
                <x-forms.select wire:model.live="source" label="Source" id="source">
                    <option value="">All Sources</option>
                    @foreach ($availableSources as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-forms.select>
            </div>
        @endif

        @if ($hasActiveFilters)
            <x-forms.button wire:click="clearFilters">Clear Filters</x-forms.button>
        @endif
    </div>

    {{-- Pagination --}}
    <div class="flex items-end gap-2 mb-4">
        <h3>Results <span class="text-xs">({{ $deploymentsCount }})</span></h3>
        @if ($deploymentsCount > 0)
            <div class="flex items-center gap-2">
                <x-forms.button disabled="{{ !$showPrev }}" wire:click="previousPage">
                    <svg class="w-4 h-4" viewBox="0 0 24 24">
                        <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                            stroke-width="2" d="m14 6l-6 6l6 6z" />
                    </svg>
                </x-forms.button>
                <span class="text-sm text-gray-600 dark:text-gray-400 px-2">
                    Page {{ $currentPage }} of {{ $totalPages }}
                </span>
                <x-forms.button disabled="{{ !$showNext }}" wire:click="nextPage">
                    <svg class="w-4 h-4" viewBox="0 0 24 24">
                        <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                            stroke-width="2" d="m10 18l6-6l-6-6z" />
                    </svg>
                </x-forms.button>
            </div>
        @endif
    </div>

    {{-- Deployments list --}}
    <div class="flex flex-col gap-2">
        @forelse ($deployments as $deployment)
            @php
                $application = $deployment->application;
                $deploymentLink = data_get($deployment, 'deployment_url');

                if (!$deploymentLink && $application) {
                    $env = $application->environment;
                    $proj = $env?->project;
                    if ($proj && $env) {
                        $deploymentLink = route('project.application.deployment.show', [
                            'project_uuid' => $proj->uuid,
                            'environment_uuid' => $env->uuid,
                            'application_uuid' => $application->uuid,
                            'deployment_uuid' => $deployment->deployment_uuid,
                        ]);
                    }
                }
            @endphp
            <div @class([
                'p-4 border-l-2 bg-white dark:bg-coolgray-100 rounded-r',
                'border-blue-500/50 border-dashed' => data_get($deployment, 'status') === 'in_progress',
                'border-purple-500/50 border-dashed' => data_get($deployment, 'status') === 'queued',
                'border-white border-dashed' => data_get($deployment, 'status') === 'cancelled-by-user',
                'border-error' => data_get($deployment, 'status') === 'failed',
                'border-success' => data_get($deployment, 'status') === 'finished',
            ])>
                <a href="{{ $deploymentLink }}" {{ wireNavigate() }} class="block">
                    <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                        {{-- Status badge --}}
                        <div class="flex items-center gap-3 flex-shrink-0">
                            <span @class([
                                'px-3 py-1 rounded-md text-xs font-medium shadow-xs whitespace-nowrap',
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
                                        default => ucfirst(data_get($deployment, 'status')),
                                    };
                                @endphp
                                {{ $statusText }}
                            </span>
                        </div>

                        {{-- Deployment info --}}
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium dark:text-white text-gray-900 truncate">
                                    {{ data_get($deployment, 'application_name') ?: 'Unknown Application' }}
                                </span>

                                {{-- Deployment type badge --}}
                                <span class="bg-gray-200/70 dark:bg-gray-600/20 px-2 py-0.5 rounded-md text-xs text-gray-800 dark:text-gray-100 border border-gray-400/30">
                                    @if (data_get($deployment, 'is_webhook'))
                                        Webhook
                                        @if (data_get($deployment, 'pull_request_id'))
                                            | PR #{{ data_get($deployment, 'pull_request_id') }}
                                        @endif
                                    @elseif (data_get($deployment, 'pull_request_id'))
                                        PR #{{ data_get($deployment, 'pull_request_id') }}
                                    @elseif (data_get($deployment, 'rollback') === true)
                                        Rollback
                                    @elseif (data_get($deployment, 'is_api'))
                                        API
                                    @else
                                        Manual
                                    @endif
                                </span>
                            </div>

                            <div class="flex flex-wrap gap-x-4 gap-y-1 mt-1 text-sm text-gray-600 dark:text-gray-400">
                                @if ($application)
                                    @php
                                        $env = $application->environment;
                                        $proj = $env?->project;
                                    @endphp
                                    @if ($proj)
                                        <span class="truncate">{{ $proj->name }} / {{ $env->name }}</span>
                                    @endif
                                @endif

                                @if (data_get($deployment, 'server_name'))
                                    <span class="flex items-center gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                            <path d="M3 4m0 3a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v2a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3z" />
                                            <path d="M15 20h-9a3 3 0 0 1 -3 -3v-2a3 3 0 0 1 3 -3h12" />
                                            <path d="M7 8v.01" />
                                            <path d="M7 16v.01" />
                                        </svg>
                                        {{ data_get($deployment, 'server_name') }}
                                    </span>
                                @endif

                                @if (data_get($deployment, 'commit'))
                                    <span class="flex items-center gap-1 font-mono text-xs">
                                        {{ substr(data_get($deployment, 'commit'), 0, 7) }}
                                    </span>
                                @endif
                            </div>
                        </div>

                        {{-- Timestamp --}}
                        <div class="flex-shrink-0 text-right text-sm text-gray-500 dark:text-gray-400">
                            @if (data_get($deployment, 'status') === 'queued')
                                <span>Queued {{ \Carbon\Carbon::parse(data_get($deployment, 'created_at'))->diffForHumans() }}</span>
                            @elseif (data_get($deployment, 'status') === 'in_progress')
                                <span>Running for {{ calculateDuration(data_get($deployment, 'created_at'), now()) }}</span>
                            @elseif (data_get($deployment, 'finished_at'))
                                <span>{{ \Carbon\Carbon::parse(data_get($deployment, 'finished_at'))->diffForHumans() }}</span>
                                <div class="text-xs">{{ calculateDuration(data_get($deployment, 'created_at'), data_get($deployment, 'finished_at')) }}</div>
                            @else
                                <span>{{ \Carbon\Carbon::parse(data_get($deployment, 'created_at'))->diffForHumans() }}</span>
                            @endif
                        </div>
                    </div>
                </a>
            </div>
        @empty
            <div class="p-8 text-center text-gray-500 dark:text-gray-400 bg-white dark:bg-coolgray-100 rounded">
                @if ($hasActiveFilters)
                    <p>No deployments match your filters.</p>
                    <button wire:click="clearFilters" class="mt-2 text-sm underline hover:no-underline">Clear all filters</button>
                @else
                    <p>No deployments found.</p>
                    <p class="text-sm mt-1">Deploy your first application to see deployments here.</p>
                @endif
            </div>
        @endforelse
    </div>
</div>
