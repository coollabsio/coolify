<div>
    <x-slot:title>Deployments | Coolify</x-slot:title>
    <h1>Deployments</h1>
    <div class="subtitle">All deployments across your projects, applications, and servers.</div>

    <div class="flex flex-col gap-4 pb-10" @if ($this->currentPage === 1 && (!$this->status || $this->status === 'in_progress' || $this->status === 'queued')) wire:poll.5000ms @endif>
        {{-- Filters --}}
        <div class="flex flex-wrap items-end gap-4 p-4 bg-white border dark:bg-coolgray-100 dark:border-coolgray-200 border-neutral-300 rounded-md">
            <div class="flex-1 min-w-[150px]">
                <label for="status" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Status</label>
                <select id="status" wire:model.live="status" class="w-full rounded-md border-gray-300 dark:border-coolgray-200 dark:bg-coolgray-200 dark:text-white shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                    @foreach ($this->statuses as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex-1 min-w-[150px]">
                <label for="server_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Server</label>
                <select id="server_id" wire:model.live="server_id" class="w-full rounded-md border-gray-300 dark:border-coolgray-200 dark:bg-coolgray-200 dark:text-white shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                    <option value="">All Servers</option>
                    @foreach ($this->servers as $server)
                        <option value="{{ $server->id }}">{{ $server->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex-1 min-w-[150px]">
                <label for="project_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Project</label>
                <select id="project_id" wire:model.live="project_id" class="w-full rounded-md border-gray-300 dark:border-coolgray-200 dark:bg-coolgray-200 dark:text-white shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                    <option value="">All Projects</option>
                    @foreach ($this->projects as $project)
                        <option value="{{ $project->id }}">{{ $project->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex-1 min-w-[150px]">
                <label for="application_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Application</label>
                <select id="application_id" wire:model.live="application_id" class="w-full rounded-md border-gray-300 dark:border-coolgray-200 dark:bg-coolgray-200 dark:text-white shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                    <option value="">All Applications</option>
                    @foreach ($this->applications as $application)
                        <option value="{{ $application->id }}">{{ $application->name }}</option>
                    @endforeach
                </select>
            </div>
            @if ($status || $server_id || $project_id || $application_id)
                <x-forms.button wire:click="resetFilters">Clear Filters</x-forms.button>
            @endif
        </div>

        {{-- Header with pagination --}}
        <div class="flex items-end gap-2">
            <h2>Deployments <span class="text-xs">({{ $this->deploymentsCount }})</span></h2>
            @if ($this->deploymentsCount > 0)
                <div class="flex items-center gap-2">
                    <x-forms.button disabled="{{ !$showPrev }}" wire:click="previousPage">
                        <svg class="w-4 h-4" viewBox="0 0 24 24">
                            <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="2" d="m14 6l-6 6l6 6z" />
                        </svg>
                    </x-forms.button>
                    <span class="text-sm text-gray-600 dark:text-gray-400 px-2">
                        Page {{ $currentPage }} of {{ max(1, ceil($this->deploymentsCount / $defaultTake)) }}
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
            @forelse ($this->deployments as $deployment)
                @php
                    $deploymentUrl = $this->getDeploymentUrl($deployment);
                    $applicationUrl = $this->getApplicationUrl($deployment);
                    $application = $deployment->application;
                    $server = $deployment->application?->destination?->server ?? \App\Models\Server::find($deployment->server_id);
                @endphp
                <div @class([
                    'p-4 border-l-2 bg-white dark:bg-coolgray-100 rounded-r-md',
                    'border-blue-500/50 border-dashed' => $deployment->status === 'in_progress',
                    'border-purple-500/50 border-dashed' => $deployment->status === 'queued',
                    'border-white border-dashed' => $deployment->status === 'cancelled-by-user',
                    'border-error' => $deployment->status === 'failed',
                    'border-success' => $deployment->status === 'finished',
                ])>
                    <div class="flex flex-col gap-2">
                        {{-- Header: Status + Application + Project/Environment --}}
                        <div class="flex flex-wrap items-center gap-2">
                            <span @class([
                                'px-3 py-1 rounded-md text-xs font-medium shadow-xs',
                                'bg-blue-100/80 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300' => $deployment->status === 'in_progress',
                                'bg-purple-100/80 text-purple-700 dark:bg-purple-500/20 dark:text-purple-300' => $deployment->status === 'queued',
                                'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200' => $deployment->status === 'failed',
                                'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200' => $deployment->status === 'finished',
                                'bg-gray-100 text-gray-700 dark:bg-gray-600/30 dark:text-gray-300' => $deployment->status === 'cancelled-by-user',
                            ])>
                                @php
                                    $statusText = match ($deployment->status) {
                                        'finished' => 'Success',
                                        'in_progress' => 'In Progress',
                                        'cancelled-by-user' => 'Cancelled',
                                        'queued' => 'Queued',
                                        default => ucfirst($deployment->status),
                                    };
                                @endphp
                                {{ $statusText }}
                            </span>
                            @if ($application)
                                <a href="{{ $applicationUrl }}" {{ wireNavigate() }} class="font-semibold text-gray-900 dark:text-white hover:underline">
                                    {{ $deployment->application_name ?? $application->name }}
                                </a>
                                @if ($application->environment?->project)
                                    <span class="text-gray-500 dark:text-gray-400">in</span>
                                    <span class="text-gray-600 dark:text-gray-300">
                                        {{ $application->environment->project->name }} / {{ $application->environment->name }}
                                    </span>
                                @endif
                            @else
                                <span class="font-semibold text-gray-900 dark:text-white">
                                    {{ $deployment->application_name ?? 'Unknown Application' }}
                                </span>
                            @endif
                        </div>

                        {{-- Server info --}}
                        <div class="text-sm text-gray-600 dark:text-gray-400">
                            <span class="font-medium">Server:</span> {{ $deployment->server_name ?? $server?->name ?? 'Unknown' }}
                        </div>

                        {{-- Timestamps --}}
                        @if ($deployment->status !== 'queued')
                            <div class="text-gray-600 dark:text-gray-400 text-sm">
                                Started: {{ formatDateInServerTimezone($deployment->created_at, $server) }}
                                @if ($deployment->status !== 'in_progress' && $deployment->status !== 'cancelled-by-user' && $deployment->finished_at)
                                    <br>Ended: {{ formatDateInServerTimezone($deployment->finished_at, $server) }}
                                    <br>Duration: {{ calculateDuration($deployment->created_at, $deployment->finished_at) }}
                                    <br>Finished {{ \Carbon\Carbon::parse($deployment->finished_at)->diffForHumans() }}
                                @elseif($deployment->status === 'in_progress')
                                    <br>Running for: {{ calculateDuration($deployment->created_at, now()) }}
                                @endif
                            </div>
                        @endif

                        {{-- Commit info --}}
                        @if ($deployment->commit)
                            <div class="text-gray-600 dark:text-gray-400 text-sm">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium">Commit:</span>
                                    @if ($application && method_exists($application, 'gitCommitLink'))
                                        <a href="{{ $application->gitCommitLink($deployment->commit) }}" target="_blank" class="underline">
                                            {{ substr($deployment->commit, 0, 7) }}
                                        </a>
                                    @else
                                        <span>{{ substr($deployment->commit, 0, 7) }}</span>
                                    @endif
                                    @if ($deployment->commitMessage())
                                        <span class="text-gray-600 dark:text-gray-400">-</span>
                                        <span class="text-gray-600 dark:text-gray-400 truncate max-w-md">
                                            {{ Str::limit(Str::before($deployment->commitMessage(), "\n"), 80) }}
                                        </span>
                                    @endif
                                    <span class="bg-gray-200/70 dark:bg-gray-600/20 px-2 py-0.5 rounded-md text-xs text-gray-800 dark:text-gray-100 border border-gray-400/30">
                                        @if ($deployment->is_webhook)
                                            Webhook
                                            @if ($deployment->pull_request_id)
                                                | PR #{{ $deployment->pull_request_id }}
                                            @endif
                                        @elseif ($deployment->pull_request_id)
                                            PR #{{ $deployment->pull_request_id }}
                                        @elseif ($deployment->rollback === true)
                                            Rollback
                                        @elseif ($deployment->is_api)
                                            API
                                        @else
                                            Manual
                                        @endif
                                    </span>
                                </div>
                            </div>
                        @endif

                        {{-- View deployment link --}}
                        @if ($deploymentUrl)
                            <div class="mt-1">
                                <a href="{{ $deploymentUrl }}" {{ wireNavigate() }} class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">
                                    View deployment logs &rarr;
                                </a>
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="p-4 text-center text-gray-500 dark:text-gray-400 bg-white dark:bg-coolgray-100 rounded-md">
                    No deployments found.
                    @if ($status || $server_id || $project_id || $application_id)
                        <button wire:click="resetFilters" class="text-indigo-600 dark:text-indigo-400 hover:underline ml-1">Clear filters</button>
                    @endif
                </div>
            @endforelse
        </div>
    </div>
</div>
