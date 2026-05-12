<div>
    <x-slot:title>Deployments | Coolify</x-slot>
    <h1>Deployments</h1>

    <div class="flex flex-col gap-4 pb-10" wire:poll.5000ms="loadDeployments">
        <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
            <div>
                <h2>All deployments <span class="text-xs">({{ $deploymentsCount }})</span></h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">Latest 50 deployments across team. Auto-refresh every 5s.</p>
            </div>
            <div class="flex gap-2">
                <x-forms.button type="button" wire:click="clearFilters">Clear filters</x-forms.button>
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-4">
            <div>
                <x-forms.select wire:model.live="status" id="status" label="Status">
                    <option value="">All statuses</option>
                    <option value="queued">Queued</option>
                    <option value="in_progress">In Progress</option>
                    <option value="finished">Finished</option>
                    <option value="failed">Failed</option>
                    <option value="cancelled-by-user">Cancelled</option>
                </x-forms.select>
            </div>
            @if ($servers->count() > 1)
            <div>
                <x-forms.select wire:model.live="serverId" id="serverId" label="Server">
                    <option value="">All servers</option>
                    @foreach ($servers as $server)
                        <option value="{{ $server->id }}">{{ $server->name }}</option>
                    @endforeach
                </x-forms.select>
            </div>
            @endif
            @if ($projects->count() > 1)
            <div>
                <x-forms.select wire:model.live="projectUuid" id="projectUuid" label="Project">
                    <option value="">All projects</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->uuid }}">{{ $project->name }}</option>
                    @endforeach
                </x-forms.select>
            </div>
            @endif
            <div>
                <x-forms.select wire:model.live="source" id="source" label="Source">
                    <option value="">All sources</option>
                    <option value="manual">Manual</option>
                    <option value="webhook">Webhook</option>
                    <option value="api">API</option>
                    <option value="rollback">Rollback</option>
                    <option value="pull-request">Pull Request</option>
                </x-forms.select>
            </div>
        </div>

        @forelse ($deployments as $deployment)
            @php
                $application = $deployment->application;
                $project = $application?->environment?->project;
                $deploymentUrl = $this->deploymentUrl($deployment);
                $statusText = match (data_get($deployment, 'status')) {
                    'finished' => 'Success',
                    'in_progress' => 'In Progress',
                    'cancelled-by-user' => 'Cancelled',
                    'queued' => 'Queued',
                    default => ucfirst(data_get($deployment, 'status')),
                };
            @endphp
            <div @class([
                'p-3 border-l-2 bg-white dark:bg-coolgray-100 rounded-r-md',
                'border-blue-500/50 border-dashed' => data_get($deployment, 'status') === 'in_progress',
                'border-purple-500/50 border-dashed' => data_get($deployment, 'status') === 'queued',
                'border-white border-dashed' => data_get($deployment, 'status') === 'cancelled-by-user',
                'border-error' => data_get($deployment, 'status') === 'failed',
                'border-success' => data_get($deployment, 'status') === 'finished',
            ])>
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2 mb-2">
                            <span @class([
                                'px-3 py-1 rounded-md text-xs font-medium shadow-xs',
                                'bg-blue-100/80 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300' => data_get($deployment, 'status') === 'in_progress',
                                'bg-purple-100/80 text-purple-700 dark:bg-purple-500/20 dark:text-purple-300' => data_get($deployment, 'status') === 'queued',
                                'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200' => data_get($deployment, 'status') === 'failed',
                                'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200' => data_get($deployment, 'status') === 'finished',
                                'bg-gray-100 text-gray-700 dark:bg-gray-600/30 dark:text-gray-300' => data_get($deployment, 'status') === 'cancelled-by-user',
                            ])>{{ $statusText }}</span>

                            @if ($deployment->pull_request_id)
                                <span class="bg-gray-200/70 dark:bg-gray-600/20 px-2 py-0.5 rounded-md text-xs text-gray-800 dark:text-gray-100 border border-gray-400/30">PR #{{ $deployment->pull_request_id }}</span>
                            @endif

                            @if ($deployment->rollback)
                                <span class="bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-200 px-2 py-0.5 rounded-md text-xs">Rollback</span>
                            @elseif ($deployment->is_api)
                                <span class="bg-gray-200/70 dark:bg-gray-600/20 px-2 py-0.5 rounded-md text-xs text-gray-800 dark:text-gray-100 border border-gray-400/30">API</span>
                            @elseif ($deployment->is_webhook)
                                <span class="bg-gray-200/70 dark:bg-gray-600/20 px-2 py-0.5 rounded-md text-xs text-gray-800 dark:text-gray-100 border border-gray-400/30">Webhook</span>
                            @else
                                <span class="bg-gray-200/70 dark:bg-gray-600/20 px-2 py-0.5 rounded-md text-xs text-gray-800 dark:text-gray-100 border border-gray-400/30">Manual</span>
                            @endif
                        </div>

                        <div class="font-medium truncate">{{ $application?->name ?? $deployment->application_name ?? 'Unknown application' }}</div>
                        <div class="text-sm text-gray-600 dark:text-gray-400 truncate">
                            Project: {{ $project?->name ?? 'Unknown project' }}
                        </div>
                        @if ($deployment->server_name)
                            <div class="text-sm text-gray-600 dark:text-gray-400 truncate">
                                Server: {{ $deployment->server_name }}
                            </div>
                        @endif
                        <div class="text-sm text-gray-600 dark:text-gray-400">
                            Started: {{ $deployment->created_at?->diffForHumans() }}
                            @if ($deployment->finished_at)
                                <span class="mx-1">•</span>
                                Duration: {{ calculateDuration($deployment->created_at, $deployment->finished_at) }}
                            @endif
                        </div>

                        @if ($deployment->commit)
                            <div class="text-sm text-gray-600 dark:text-gray-400 mt-2 break-all">
                                Commit:
                                @if ($application)
                                    <a href="{{ $application->gitCommitLink($deployment->commit) }}" target="_blank" class="underline">
                                        {{ substr($deployment->commit, 0, 7) }}
                                    </a>
                                @else
                                    <span>{{ substr($deployment->commit, 0, 7) }}</span>
                                @endif
                                @if ($deployment->commitMessage())
                                    <span class="text-gray-500">— {{ Str::before($deployment->commitMessage(), "\n") }}</span>
                                @endif
                            </div>
                        @endif
                    </div>

                    @if ($deploymentUrl)
                        <div class="shrink-0">
                            <a href="{{ $deploymentUrl }}" {{ wireNavigate() }} class="text-sm underline text-primary dark:text-white">Open logs</a>
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <div>No deployments found</div>
        @endforelse
    </div>
</div>
