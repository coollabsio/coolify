<div wire:poll.5s="reloadDeployments">
    <x-slot:title>Deployments | Coolify</x-slot>
    
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1>Deployments</h1>
            <div class="subtitle">All deployments from {{ currentTeam()->name }}</div>
        </div>
        @if ($isPolling)
            <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                <x-loading class="w-4 h-4" />
                <span>Updating...</span>
            </div>
        @endif
    </div>

    {{-- Filter Bar --}}
    <div class="flex items-center gap-2 mb-6 pb-4 border-b border-neutral-300 dark:border-coolgray-200">
        @if ($shouldShowProjectFilter)
            <x-dropdown>
                <x-slot:title>
                    {{ $selectedProjectId ? ($filterOptions['projects'][$selectedProjectId] ?? 'All Projects') : 'All Projects' }}
                </x-slot:title>
                <div class="flex flex-col">
                    <button wire:click="$set('selectedProjectId', null)" 
                            class="dropdown-item {{ !$selectedProjectId ? 'bg-neutral-100 dark:bg-coolgray-100' : '' }}">
                        All Projects
                    </button>
                    @foreach ($filterOptions['projects'] as $uuid => $name)
                        <button wire:click="$set('selectedProjectId', '{{ $uuid }}')" 
                                class="dropdown-item {{ $selectedProjectId === $uuid ? 'bg-neutral-100 dark:bg-coolgray-100' : '' }}">
                            {{ $name }}
                        </button>
                    @endforeach
                </div>
            </x-dropdown>
        @endif

        @if ($shouldShowServerFilter)
            <x-dropdown>
                <x-slot:title>
                    {{ $selectedServerId ? ($filterOptions['servers'][$selectedServerId] ?? 'All Servers') : 'All Servers' }}
                </x-slot:title>
                <div class="flex flex-col">
                    <button wire:click="$set('selectedServerId', null)" 
                            class="dropdown-item {{ !$selectedServerId ? 'bg-neutral-100 dark:bg-coolgray-100' : '' }}">
                        All Servers
                    </button>
                    @foreach ($filterOptions['servers'] as $id => $name)
                        <button wire:click="$set('selectedServerId', {{ $id }})" 
                                class="dropdown-item {{ $selectedServerId === $id ? 'bg-neutral-100 dark:bg-coolgray-100' : '' }}">
                            {{ $name }}
                        </button>
                    @endforeach
                </div>
            </x-dropdown>
        @endif

        @if ($shouldShowApplicationFilter)
            <x-dropdown>
                <x-slot:title>
                    {{ $selectedApplicationId ? ($filterOptions['applications'][$selectedApplicationId] ?? 'All Applications') : 'All Applications' }}
                </x-slot:title>
                <div class="flex flex-col">
                    <button wire:click="$set('selectedApplicationId', null)" 
                            class="dropdown-item {{ !$selectedApplicationId ? 'bg-neutral-100 dark:bg-coolgray-100' : '' }}">
                        All Applications
                    </button>
                    @foreach ($filterOptions['applications'] as $uuid => $name)
                        <button wire:click="$set('selectedApplicationId', '{{ $uuid }}')" 
                                class="dropdown-item {{ $selectedApplicationId === $uuid ? 'bg-neutral-100 dark:bg-coolgray-100' : '' }}">
                            {{ $name }}
                        </button>
                    @endforeach
                </div>
            </x-dropdown>
        @endif

        @if ($shouldShowSourceFilter)
            <x-dropdown>
                <x-slot:title>
                    {{ $selectedSourceId ? (collect($filterOptions['sources'])->firstWhere('id', $selectedSourceId)['name'] ?? 'All Sources') : 'All Sources' }}
                </x-slot:title>
                <div class="flex flex-col">
                    <button wire:click="$set('selectedSourceId', null); $set('selectedSourceType', null)" 
                            class="dropdown-item {{ !$selectedSourceId ? 'bg-neutral-100 dark:bg-coolgray-100' : '' }}">
                        All Sources
                    </button>
                    @foreach ($filterOptions['sources'] as $source)
                        <button wire:click="$set('selectedSourceId', {{ $source['id'] }}); $set('selectedSourceType', '{{ $source['type'] }}')" 
                                class="dropdown-item {{ $selectedSourceId === $source['id'] && $selectedSourceType === $source['type'] ? 'bg-neutral-100 dark:bg-coolgray-100' : '' }}">
                            {{ $source['name'] }}
                        </button>
                    @endforeach
                </div>
            </x-dropdown>
        @endif

        <x-dropdown>
            <x-slot:title>
                {{ $selectedStatus ? ($filterOptions['statuses'][$selectedStatus] ?? 'All Statuses') : 'All Statuses' }}
            </x-slot:title>
            <div class="flex flex-col">
                <button wire:click="$set('selectedStatus', null)" 
                        class="dropdown-item {{ !$selectedStatus ? 'bg-neutral-100 dark:bg-coolgray-100' : '' }}">
                    All Statuses
                </button>
                @foreach ($filterOptions['statuses'] as $value => $label)
                    <button wire:click="$set('selectedStatus', '{{ $value }}')" 
                            class="dropdown-item {{ $selectedStatus === $value ? 'bg-neutral-100 dark:bg-coolgray-100' : '' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </x-dropdown>

        @if ($selectedProjectId || $selectedServerId || $selectedApplicationId || $selectedSourceId || $selectedStatus)
            <button wire:click="clearFilters" 
                    class="px-3 py-1.5 text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white">
                Clear filters
            </button>
        @endif
    </div>

    {{-- Deployments List --}}
    <div class="flex flex-col">
        {{-- Table Header --}}
        <div class="flex items-center gap-4 px-4 py-2 border-b border-neutral-300 dark:border-coolgray-200 bg-neutral-50 dark:bg-coolgray-200 text-xs font-medium text-gray-600 dark:text-gray-400 uppercase tracking-wider">
            <div class="w-20 flex-shrink-0">ID</div>
            <div class="w-40 flex-shrink-0">Status</div>
            <div class="min-w-0 flex-1">Commit</div>
            <div class="flex-1 min-w-0">Application</div>
            <div class="w-32 flex-shrink-0">Environment</div>
        </div>
        
        @forelse ($deployments as $deployment)
            @php
                // Eager load relationships to avoid N+1 queries
                $application = $deployment->application;
                $environment = $application->environment ?? null;
                $project = $environment->project ?? null;
                
                // Format deployment ID for display (first 9 characters)
                $shortId = substr($deployment->deployment_uuid, 0, 9);
                $status = $deployment->status;
                
                // Determine if deployment is actively running (needs polling/logs)
                $isActive = in_array($status, ['in_progress', 'queued']);
                
                // Status configuration for styling and display
                // Maps status values to color schemes and display text
                $statusConfig = match($status) {
                    'finished' => ['color' => 'green', 'dot' => 'bg-green-500', 'text' => 'Ready', 'bg' => 'bg-green-100/80 dark:bg-green-900/30', 'textColor' => 'text-green-800 dark:text-green-200'],
                    'failed' => ['color' => 'red', 'dot' => 'bg-red-500', 'text' => 'Error', 'bg' => 'bg-red-100 dark:bg-red-900/30', 'textColor' => 'text-red-800 dark:text-red-200'],
                    'in_progress' => ['color' => 'yellow', 'dot' => 'bg-yellow-500', 'text' => 'In Progress', 'bg' => 'bg-yellow-100/80 dark:bg-yellow-900/30', 'textColor' => 'text-yellow-800 dark:text-yellow-200'],
                    'queued' => ['color' => 'purple', 'dot' => 'bg-purple-500', 'text' => 'Queued', 'bg' => 'bg-purple-100/80 dark:bg-purple-900/30', 'textColor' => 'text-purple-800 dark:text-purple-200'],
                    'cancelled-by-user' => ['color' => 'gray', 'dot' => 'bg-gray-500', 'text' => 'Cancelled', 'bg' => 'bg-gray-100 dark:bg-gray-900/30', 'textColor' => 'text-gray-800 dark:text-gray-200'],
                    default => ['color' => 'gray', 'dot' => 'bg-gray-500', 'text' => ucfirst($status), 'bg' => 'bg-gray-100 dark:bg-gray-900/30', 'textColor' => 'text-gray-800 dark:text-gray-200'],
                };
                
                // Format commit information for display
                $commitHash = $deployment->commit ? substr($deployment->commit, 0, 7) : null;
                $commitMessage = $deployment->commitMessage();
                $branchName = $application->git_branch ?? 'main';
                $isCurrent = false; // TODO: Determine if this is the current deployment
                
                // Only load logs for active deployments to avoid unnecessary processing
                $logLines = $isActive ? $this->getLogLines($deployment) : collect();
            @endphp
            
            {{-- Deployment Row with Expandable Logs --}}
            {{-- Component-level polling handles updates, no need for per-row polling --}}
            <div x-data="{ expanded: false }" 
                 class="border-b border-neutral-300 dark:border-coolgray-200">
                <div class="flex items-center gap-4 px-4 py-3 hover:bg-neutral-100 dark:hover:bg-black transition-colors group whitespace-nowrap">
                    {{-- Deployment ID -- Clickable to deployment page --}}
                    <div class="w-20 flex-shrink-0 whitespace-nowrap">
                        @if ($project && $environment && $application)
                            <a href="{{ route('project.application.deployment.show', [
                                'project_uuid' => $project->uuid,
                                'environment_uuid' => $environment->uuid,
                                'application_uuid' => $application->uuid,
                                'deployment_uuid' => $deployment->deployment_uuid
                            ]) }}"
                               class="font-mono text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white hover:underline">
                                {{ $shortId }}
                            </a>
                        @else
                            <span class="font-mono text-sm text-gray-600 dark:text-gray-400">{{ $shortId }}</span>
                        @endif
                    </div>
                    
                    {{-- Status -- Not clickable --}}
                    <div class="flex items-center gap-2 w-40 flex-shrink-0 whitespace-nowrap">
                        @if ($isActive)
                            <x-loading class="w-4 h-4 text-coollabs dark:text-warning flex-shrink-0" />
                        @else
                            <div class="w-2 h-2 rounded-full {{ $statusConfig['dot'] }} flex-shrink-0"></div>
                        @endif
                        <span class="text-sm font-medium {{ $statusConfig['textColor'] }} whitespace-nowrap">
                            {{ $statusConfig['text'] }}
                        </span>
                    </div>
                    
                    {{-- Commit Info -- Clickable to GitHub commit page --}}
                    <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400 min-w-0 flex-1">
                        @if ($commitHash)
                            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            <span class="flex-shrink-0">{{ $branchName }}</span>
                            @if ($application && $deployment->commit)
                                <a href="{{ $application->gitCommitLink($deployment->commit) }}"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   class="font-mono text-xs flex-shrink-0 hover:text-gray-900 dark:hover:text-white hover:underline">
                                    {{ $commitHash }}
                                </a>
                            @else
                                <span class="font-mono text-xs flex-shrink-0">{{ $commitHash }}</span>
                            @endif
                            @if ($commitMessage)
                                <span class="truncate max-w-[200px]" title="{{ $commitMessage }}">{{ Str::before($commitMessage, "\n") }}</span>
                            @endif
                        @endif
                    </div>
                    
                    {{-- Application Info -- Clickable to application configuration page --}}
                    <div class="flex items-center gap-2 flex-1 min-w-0">
                        @if ($project && $environment && $application)
                            <a href="{{ route('project.application.configuration', [
                                'project_uuid' => $project->uuid,
                                'environment_uuid' => $environment->uuid,
                                'application_uuid' => $application->uuid
                            ]) }}"
                               class="font-medium text-sm dark:text-white truncate hover:text-gray-900 dark:hover:text-gray-200 hover:underline">
                                {{ $application->name }}
                            </a>
                        @else
                            <span class="font-medium text-sm dark:text-white truncate">
                                {{ $application->name }}
                            </span>
                        @endif
                    </div>
                    
                    {{-- Environment Badge -- Clickable to environment page --}}
                    <div class="flex items-center gap-2 w-32 flex-shrink-0 whitespace-nowrap">
                        @if ($environment && $project)
                            <a href="{{ route('project.resource.index', [
                                'project_uuid' => $project->uuid,
                                'environment_uuid' => $environment->uuid
                            ]) }}"
                               class="px-2 py-0.5 text-xs font-medium rounded bg-neutral-100 dark:bg-coolgray-100 text-gray-700 dark:text-gray-300 whitespace-nowrap hover:bg-neutral-200 dark:hover:bg-coolgray-200 transition-colors">
                                {{ ucfirst($environment->name) }}
                            </a>
                            @if ($isCurrent)
                                <span class="w-2 h-2 rounded-full bg-blue-500 flex-shrink-0" title="Current deployment"></span>
                            @endif
                        @elseif ($environment)
                            <span class="px-2 py-0.5 text-xs font-medium rounded bg-neutral-100 dark:bg-coolgray-100 text-gray-700 dark:text-gray-300 whitespace-nowrap">
                                {{ ucfirst($environment->name) }}
                            </span>
                        @endif
                    </div>
                    
                    {{-- Expand/Collapse Button for Logs --}}
                    {{-- Only show expand button if logs are available --}}
                    @if ($logLines->isNotEmpty())
                        <button @click="expanded = !expanded" 
                                class="p-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition-colors"
                                title="Toggle deployment logs">
                            <svg class="w-5 h-5 transition-transform duration-200" 
                                 :class="{ 'rotate-180': expanded }" 
                                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>
                    @endif
                </div>
                
                {{-- Expandable Logs Section --}}
                {{-- Shows real-time deployment logs when expanded --}}
                @if ($logLines->isNotEmpty())
                    <div x-show="expanded" 
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 max-h-0"
                         x-transition:enter-end="opacity-100 max-h-[500px]"
                         x-transition:leave="transition ease-in duration-150"
                         x-transition:leave-start="opacity-100 max-h-[500px]"
                         x-transition:leave-end="opacity-0 max-h-0"
                         x-cloak
                         class="overflow-hidden">
                        <div class="px-4 pb-4 bg-neutral-50 dark:bg-coolgray-200 border-t border-neutral-200 dark:border-coolgray-300">
                            <div class="mt-3 p-3 bg-white dark:bg-coolgray-100 rounded border border-neutral-200 dark:border-coolgray-300 max-h-[400px] overflow-y-auto font-mono text-xs">
                                @foreach ($logLines as $line)
                                    <div @class([
                                        'mt-2' => isset($line['command']) && $line['command'],
                                        'flex gap-2',
                                    ])>
                                        <span class="shrink-0 text-gray-500">{{ $line['timestamp'] ?? '' }}</span>
                                        <span @class([
                                            'text-success dark:text-warning' => $line['hidden'] ?? false,
                                            'text-red-500' => $line['stderr'] ?? false,
                                            'font-bold' => isset($line['command']) && $line['command'],
                                            'whitespace-pre-wrap',
                                        ])>{!! $line['line'] ?? '' !!}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="py-12 text-center text-gray-600 dark:text-gray-400">
                <p class="text-lg font-medium mb-2">No deployments found</p>
                <p class="text-sm">Try adjusting your filters or check back later.</p>
            </div>
        @endforelse
    </div>

    {{-- Pagination --}}
    @if ($deployments->hasPages())
        <div class="mt-6 flex items-center justify-between">
            <div class="text-sm text-gray-600 dark:text-gray-400">
                Showing {{ $deployments->firstItem() }} to {{ $deployments->lastItem() }} of {{ $deployments->total() }} deployments
            </div>
            <div class="flex items-center gap-2">
                @if ($deployments->onFirstPage())
                    <button disabled class="px-3 py-1.5 text-sm border border-neutral-300 dark:border-coolgray-200 rounded-md opacity-50 cursor-not-allowed">
                        Previous
                    </button>
                @else
                    <button wire:click="previousPage" class="px-3 py-1.5 text-sm border border-neutral-300 dark:border-coolgray-200 rounded-md hover:bg-neutral-100 dark:hover:bg-coolgray-100 transition-colors">
                        Previous
                    </button>
                @endif
                
                <span class="text-sm text-gray-600 dark:text-gray-400">
                    Page {{ $deployments->currentPage() }} of {{ $deployments->lastPage() }}
                </span>
                
                @if ($deployments->hasMorePages())
                    <button wire:click="nextPage" class="px-3 py-1.5 text-sm border border-neutral-300 dark:border-coolgray-200 rounded-md hover:bg-neutral-100 dark:hover:bg-coolgray-100 transition-colors">
                        Next
                    </button>
                @else
                    <button disabled class="px-3 py-1.5 text-sm border border-neutral-300 dark:border-coolgray-200 rounded-md opacity-50 cursor-not-allowed">
                        Next
                    </button>
                @endif
            </div>
        </div>
    @endif
</div>

