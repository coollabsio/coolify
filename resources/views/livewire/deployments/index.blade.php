<div>
    <x-slot:title>
        Deployments | Coolify
    </x-slot>
    <div class="flex gap-2">
        <h1>Deployments</h1>
    </div>
    <div class="subtitle">All deployments across all your projects.</div>

    <div class="pb-4">
        <div class="flex flex-wrap items-end gap-2">
            @if (count($projects) > 1)
                <x-forms.select wire:model.live="projectFilter" label="Project">
                    <option value="">All Projects</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project['id'] }}">{{ $project['name'] }}</option>
                    @endforeach
                </x-forms.select>
            @endif

            @if (count($servers) > 1)
                <x-forms.select wire:model.live="serverFilter" label="Server">
                    <option value="">All Servers</option>
                    @foreach ($servers as $server)
                        <option value="{{ $server['id'] }}">{{ $server['name'] }}</option>
                    @endforeach
                </x-forms.select>
            @endif

            @if (count($sources) > 1)
                <x-forms.select wire:model.live="sourceFilter" label="Source">
                    <option value="">All Sources</option>
                    @foreach ($sources as $source)
                        <option value="{{ $source['id'] }}">{{ $source['name'] }}</option>
                    @endforeach
                </x-forms.select>
            @endif

            <x-forms.select wire:model.live="statusFilter" label="Status">
                <option value="">All Statuses</option>
                <option value="queued">Queued</option>
                <option value="in_progress">In Progress</option>
                <option value="finished">Success</option>
                <option value="failed">Failed</option>
                <option value="cancelled-by-user">Cancelled</option>
            </x-forms.select>

            @if ($hasActiveFilters)
                <x-forms.button wire:click="clearFilters">Clear Filters</x-forms.button>
            @endif
        </div>
    </div>

    <div wire:poll.5000ms="$refresh" class="flex flex-col w-full gap-2 pb-10">
        @forelse ($deployments as $deployment)
            @php
                $statusColor = match ($deployment->status) {
                    'in_progress' => 'border-blue-500/50 border-dashed',
                    'queued' => 'border-purple-500/50 border-dashed',
                    'finished' => 'border-success',
                    'failed' => 'border-error',
                    'cancelled-by-user' => 'border-white border-dashed',
                    default => 'border-neutral-300 dark:border-coolgray-200',
                };
                $statusText = match ($deployment->status) {
                    'in_progress' => 'In Progress',
                    'queued' => 'Queued',
                    'finished' => 'Success',
                    'failed' => 'Failed',
                    'cancelled-by-user' => 'Cancelled',
                    default => ucfirst($deployment->status),
                };
                $statusBadgeBg = match ($deployment->status) {
                    'in_progress' => 'bg-blue-100/80 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300',
                    'queued' => 'bg-purple-100/80 text-purple-700 dark:bg-purple-500/20 dark:text-purple-300',
                    'finished' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200',
                    'failed' => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200',
                    'cancelled-by-user' => 'bg-gray-100 text-gray-700 dark:bg-gray-600/30 dark:text-gray-300',
                    default => 'bg-gray-100 text-gray-700 dark:bg-gray-600/30 dark:text-gray-300',
                };
                $project = $deployment->application?->environment?->project;
                $appLink = $deployment->deployment_url
                    ? $deployment->deployment_url
                    : ($project && $deployment->application
                        ? route('project.application.deployment.show', [
                            'project_uuid' => $project->uuid,
                            'environment_uuid' => $deployment->application->environment?->uuid,
                            'application_uuid' => $deployment->application->uuid,
                            'deployment_uuid' => $deployment->deployment_uuid,
                        ])
                        : '#');
            @endphp
            <div class="p-2 border-l-2 bg-white dark:bg-coolgray-100 {{ $statusColor }}">
                <a href="{{ $appLink }}" {{ wireNavigate() }} class="block">
                    <div class="flex flex-col gap-1">
                        {{-- Top row: Status badge + Application name + Project/Environment --}}
                        <div class="flex items-center flex-wrap gap-2">
                            <span class="px-3 py-1 rounded-md text-xs font-medium shadow-xs {{ $statusBadgeBg }}">
                                {{ $statusText }}
                            </span>
                            <span class="text-sm font-medium dark:text-white">
                                {{ $deployment->application_name ?? 'Unknown' }}
                            </span>
                            @if ($project)
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $project->name }}
                                    @if ($deployment->application?->environment)
                                        / {{ $deployment->application->environment->name }}
                                    @endif
                                </span>
                            @endif
                        </div>

                        {{-- Server + Commit + Duration --}}
                        <div class="flex items-center flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600 dark:text-gray-400">
                            @if ($deployment->server_name)
                                <span>Server: {{ $deployment->server_name }}</span>
                            @endif

                            @if ($deployment->commit)
                                <span>
                                    Commit:
                                    <span class="font-mono">{{ substr($deployment->commit, 0, 7) }}</span>
                                </span>
                            @endif

                            @if ($deployment->commit_message)
                                <span class="truncate max-w-xs">
                                    {{ Str::before($deployment->commit_message, "\n") }}
                                </span>
                            @endif

                            @if ($deployment->git_type)
                                <span class="bg-gray-200/70 dark:bg-gray-600/20 px-2 py-0.5 rounded-md text-xs text-gray-800 dark:text-gray-100 border border-gray-400/30">
                                    {{ ucfirst($deployment->git_type) }}
                                </span>
                            @endif
                        </div>

                        {{-- Timing --}}
                        <div class="flex items-center flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                            <span>Started: {{ $deployment->created_at?->format('Y-m-d H:i:s') ?? 'N/A' }}</span>
                            @php
                                $duration = $deployment->finished_at
                                    ? calculateDuration($deployment->created_at, $deployment->finished_at)
                                    : ($deployment->status === 'in_progress'
                                        ? calculateDuration($deployment->created_at, now())
                                        : null);
                            @endphp
                            @if ($duration)
                                <span>Duration: {{ $duration }}</span>
                            @endif
                            @if ($deployment->finished_at)
                                <span>{{ \Carbon\Carbon::parse($deployment->finished_at)->diffForHumans() }}</span>
                            @elseif($deployment->status === 'in_progress')
                                <span>Running for: {{ calculateDuration($deployment->created_at, now()) }}</span>
                            @endif
                        </div>
                    </div>
                </a>
            </div>
        @empty
            <div class="py-8 text-center text-gray-500 dark:text-gray-400">
                @if ($hasActiveFilters)
                    No deployments match your filters.
                @else
                    No deployments found yet. Start by deploying an application!
                @endif
            </div>
        @endforelse

        <div class="py-4">
            {{ $deployments->onEachSide(1)->links() }}
        </div>
    </div>
</div>
