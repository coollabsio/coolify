<div>
    <x-slot:title>Deployments | Coolify</x-slot>
    <h1>Deployments</h1>
    
    <div class="flex flex-col gap-2 pb-10" wire:poll.5000ms='reloadDeployments'>
        <div class="flex items-end gap-2 flex-wrap">
            <h2>All Deployments <span class="text-xs">({{ $deployments_count }})</span></h2>
            @if ($deployments_count > 0)
                <div class="flex items-center gap-2">
                    <x-forms.button disabled="{{ !$showPrev }}" wire:click="previousPage">
                        <svg class="w-4 h-4" viewBox="0 0 24 24">
                            <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="2" d="m14 6l-6 6l6 6z" />
                        </svg>
                    </x-forms.button>
                    <span class="text-sm text-gray-600 dark:text-gray-400 px-2">
                        Page {{ $currentPage }} of {{ ceil($deployments_count / $defaultTake) }}
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

        <!-- Filters -->
        <div class="flex flex-wrap gap-2 items-end">
            @if ($projects->count() > 1)
                <div class="flex-1 min-w-[200px]">
                    <x-forms.select id="project_filter" label="Project" wire:model.live="project_filter">
                        <option value="">All Projects</option>
                        @foreach ($projects as $project)
                            <option value="{{ $project->uuid }}">{{ $project->name }}</option>
                        @endforeach
                    </x-forms.select>
                </div>
            @endif

            @if ($servers->count() > 1)
                <div class="flex-1 min-w-[200px]">
                    <x-forms.select id="server_filter" label="Server" wire:model.live="server_filter">
                        <option value="">All Servers</option>
                        @foreach ($servers as $server)
                            <option value="{{ $server->id }}">{{ $server->name }}</option>
                        @endforeach
                    </x-forms.select>
                </div>
            @endif

            @if ($sources->count() > 1)
                <div class="flex-1 min-w-[200px]">
                    <x-forms.select id="source_filter" label="Source" wire:model.live="source_filter">
                        <option value="">All Sources</option>
                        @foreach ($sources as $source)
                            <option value="{{ $source->id }}">{{ $source->name }}</option>
                        @endforeach
                    </x-forms.select>
                </div>
            @endif

            <div class="flex-1 min-w-[200px]">
                <x-forms.select id="status_filter" label="Status" wire:model.live="status_filter">
                    <option value="">All Statuses</option>
                    <option value="queued">Queued</option>
                    <option value="in_progress">In Progress</option>
                    <option value="finished">Finished</option>
                    <option value="failed">Failed</option>
                    <option value="cancelled-by-user">Cancelled</option>
                </x-forms.select>
            </div>

            @if ($project_filter || $server_filter || $source_filter || $status_filter)
                <x-forms.button wire:click="clearFilters" class="mb-2">
                    Clear Filters
                </x-forms.button>
            @endif
        </div>

        <!-- Deployments List -->
        @forelse ($deployments as $deployment)
            <div @class([
                'p-4 border-l-4 bg-white dark:bg-coolgray-100 hover:bg-neutral-50 dark:hover:bg-coolgray-200 transition-colors cursor-pointer',
                'border-blue-500' => data_get($deployment, 'status') === 'in_progress',
                'border-purple-500' => data_get($deployment, 'status') === 'queued',
                'border-gray-400' => data_get($deployment, 'status') === 'cancelled-by-user',
                'border-red-500' => data_get($deployment, 'status') === 'failed',
                'border-green-500' => data_get($deployment, 'status') === 'finished',
            ])>
                <a href="{{ route('project.application.deployment.show', [
                    'project_uuid' => $deployment->application->environment->project->uuid,
                    'environment_uuid' => $deployment->application->environment->uuid,
                    'application_uuid' => $deployment->application->uuid,
                    'deployment_uuid' => $deployment->uuid,
                ]) }}" {{ wireNavigate() }} class="block">
                    <div class="flex flex-col gap-2">
                        <!-- Header row with status and deployment type -->
                        <div class="flex items-center justify-between flex-wrap gap-2">
                            <div class="flex items-center gap-2">
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
                                            default => ucfirst(data_get($deployment, 'status')),
                                        };
                                    @endphp
                                    {{ $statusText }}
                                </span>
                                
                                <span class="bg-gray-200/70 dark:bg-gray-600/20 px-2 py-1 rounded-md text-xs text-gray-800 dark:text-gray-100 border border-gray-400/30">
                                    @if (data_get($deployment, 'is_webhook'))
                                        Webhook
                                        @if (data_get($deployment, 'pull_request_id'))
                                            | PR #{{ data_get($deployment, 'pull_request_id') }}
                                        @endif
                                    @elseif (data_get($deployment, 'pull_request_id'))
                                        Pull Request #{{ data_get($deployment, 'pull_request_id') }}
                                    @elseif (data_get($deployment, 'rollback') === true)
                                        Rollback
                                    @elseif (data_get($deployment, 'is_api'))
                                        API
                                    @else
                                        Manual
                                    @endif
                                </span>
                            </div>

                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                {{ \Carbon\Carbon::parse(data_get($deployment, 'created_at'))->diffForHumans() }}
                            </div>
                        </div>

                        <!-- Application and server info -->
                        <div class="flex items-center gap-4 flex-wrap">
                            <div class="flex items-center gap-1 text-sm">
                                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                </svg>
                                <span class="font-medium">{{ data_get($deployment, 'application.name') }}</span>
                            </div>
                            
                            <div class="flex items-center gap-1 text-sm text-gray-600 dark:text-gray-400">
                                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M12 5l7 7-7 7" />
                                </svg>
                                <span>{{ data_get($deployment, 'application.environment.name') }}</span>
                            </div>
                            
                            @if (data_get($deployment, 'application.destination.server'))
                                <div class="flex items-center gap-1 text-sm text-gray-600 dark:text-gray-400">
                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12l7 7l7-7" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 20h16" />
                                    </svg>
                                    <span>{{ data_get($deployment, 'application.destination.server.name') }}</span>
                                </div>
                            @endif
                        </div>

                        <!-- Commit info -->
                        @if (data_get($deployment, 'commit'))
                            <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
                                </svg>
                                <a href="{{ $deployment->application->gitCommitLink(data_get($deployment, 'commit')) }}"
                                    target="_blank" class="hover:underline font-mono">
                                    {{ substr(data_get($deployment, 'commit'), 0, 7) }}
                                </a>
                                @if ($deployment->commitMessage())
                                    <span class="truncate max-w-md">
                                        {{ Str::before($deployment->commitMessage(), "\n") }}
                                    </span>
                                @endif
                            </div>
                        @endif

                        <!-- Timing info -->
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            Started: {{ formatDateInServerTimezone(data_get($deployment, 'created_at'), data_get($deployment, 'application.destination.server')) }}
                            @if ($deployment->status !== 'in_progress' && $deployment->status !== 'cancelled-by-user' && data_get($deployment, 'finished_at'))
                                | Duration: {{ calculateDuration(data_get($deployment, 'created_at'), data_get($deployment, 'finished_at')) }}
                            @elseif($deployment->status === 'in_progress')
                                | Running for: {{ calculateDuration(data_get($deployment, 'created_at'), now()) }}
                            @endif
                        </div>
                    </div>
                </a>
            </div>
        @empty
            <div class="p-8 text-center text-gray-500 dark:text-gray-400 bg-white dark:bg-coolgray-100 rounded-lg">
                No deployments found
                @if ($project_filter || $server_filter || $source_filter || $status_filter)
                    <div class="mt-2">
                        <x-forms.button wire:click="clearFilters" class="text-sm">
                            Clear Filters
                        </x-forms.button>
                    </div>
                @endif
            </div>
        @endforelse

        <!-- Pagination -->
        @if ($deployments_count > 0)
            <div class="flex items-center justify-center gap-2 mt-4">
                <x-forms.button disabled="{{ !$showPrev }}" wire:click="previousPage">
                    <svg class="w-4 h-4" viewBox="0 0 24 24">
                        <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                            stroke-width="2" d="m14 6l-6 6l6 6z" />
                    </svg>
                    Previous
                </x-forms.button>
                <span class="text-sm text-gray-600 dark:text-gray-400 px-4">
                    Page {{ $currentPage }} of {{ ceil($deployments_count / $defaultTake) }}
                </span>
                <x-forms.button disabled="{{ !$showNext }}" wire:click="nextPage">
                    Next
                    <svg class="w-4 h-4" viewBox="0 0 24 24">
                        <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                            stroke-width="2" d="m10 18l6-6l-6-6z" />
                    </svg>
                </x-forms.button>
            </div>
        @endif
    </div>
</div>
