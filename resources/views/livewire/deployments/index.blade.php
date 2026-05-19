<div>
    <x-slot:title>Deployments | Coolify</x-slot>

    <div class="flex flex-col gap-6" @if (!$deployments->currentPage() || $deployments->currentPage() === 1) wire:poll.5000ms @endif>
        <div>
            <h1>Deployments</h1>
            <div class="subtitle">Deployment history across your current team.</div>
        </div>

        <div class="rounded-sm border border-neutral-200 bg-white p-4 dark:border-coolgray-300 dark:bg-coolgray-100">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                <x-forms.select id="project" label="Project">
                    <option value="all">All projects</option>
                    @foreach ($projects as $projectOption)
                        <option value="{{ $projectOption->id }}">{{ $projectOption->name }}</option>
                    @endforeach
                </x-forms.select>

                @if ($this->showServerFilter())
                    <x-forms.select id="server" label="Server">
                        <option value="all">All servers</option>
                        @foreach ($servers as $serverOption)
                            <option value="{{ $serverOption->id }}">{{ $serverOption->name }}</option>
                        @endforeach
                    </x-forms.select>
                @endif

                @if ($this->showSourceFilter())
                    <x-forms.select id="source" label="Source">
                        <option value="all">All sources</option>
                        @foreach ($sources as $sourceOption)
                            <option value="{{ $sourceOption['value'] }}">{{ $sourceOption['label'] }}</option>
                        @endforeach
                    </x-forms.select>
                @endif

                <x-forms.select id="status" label="Status">
                    <option value="all">All statuses</option>
                    @foreach ($statuses as $statusOption)
                        <option value="{{ $statusOption['value'] }}">{{ $statusOption['label'] }}</option>
                    @endforeach
                </x-forms.select>
            </div>

            @if ($this->hasActiveFilters())
                <div class="mt-3 flex justify-end">
                    <x-forms.button wire:click="clearFilters">Clear filters</x-forms.button>
                </div>
            @endif
        </div>

        <div class="overflow-hidden rounded-sm border border-neutral-200 bg-white dark:border-coolgray-300 dark:bg-coolgray-100">
            <div class="grid grid-cols-[minmax(0,1fr)_7rem] gap-3 border-b border-neutral-200 px-4 py-2 text-xs font-bold uppercase text-neutral-500 dark:border-coolgray-300 dark:text-neutral-400 md:grid-cols-[minmax(12rem,1.4fr)_minmax(10rem,1fr)_minmax(8rem,0.9fr)_minmax(7rem,0.8fr)_8rem]">
                <div>Application</div>
                <div class="hidden md:block">Project</div>
                <div class="hidden md:block">Server</div>
                <div class="hidden md:block">Source</div>
                <div class="text-right md:text-left">Status</div>
            </div>

            <div class="divide-y divide-neutral-200 dark:divide-coolgray-300">
                @forelse ($deployments as $deployment)
                    @php
                        $deploymentUrl = $this->deploymentUrl($deployment);
                        $application = $deployment->application;
                        $environment = $application?->environment;
                        $project = $environment?->project;
                    @endphp
                    <a href="{{ $deploymentUrl ?? '#' }}" @if ($deploymentUrl) {{ wireNavigate() }} @endif
                        @class([
                            'grid grid-cols-[minmax(0,1fr)_7rem] gap-3 px-4 py-3 transition-colors md:grid-cols-[minmax(12rem,1.4fr)_minmax(10rem,1fr)_minmax(8rem,0.9fr)_minmax(7rem,0.8fr)_8rem]',
                            'hover:bg-neutral-50 dark:hover:bg-coolgray-200' => $deploymentUrl,
                            'cursor-default opacity-75' => !$deploymentUrl,
                        ])>
                        <div class="min-w-0">
                            <div class="flex min-w-0 items-center gap-2">
                                <span class="truncate font-medium text-black dark:text-white">
                                    {{ $deployment->application_name ?? $application?->name ?? 'Unknown application' }}
                                </span>
                                @if ($deployment->pull_request_id)
                                    <span class="inline-flex h-5 items-center rounded-sm border border-neutral-200 bg-neutral-100 px-1.5 text-xs font-medium text-black dark:border-coolgray-300 dark:bg-coolgray-200 dark:text-white">
                                        PR #{{ $deployment->pull_request_id }}
                                    </span>
                                @endif
                            </div>
                            <div class="mt-1 flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs text-neutral-500 dark:text-neutral-400">
                                <span>{{ $this->deploymentType($deployment) }}</span>
                                @if ($deployment->commit)
                                    <span class="font-mono">{{ str($deployment->commit)->limit(7, '') }}</span>
                                @endif
                                <span>{{ formatDateInServerTimezone($deployment->created_at, $application?->destination?->server) }}</span>
                            </div>
                            @if ($deployment->commitMessage())
                                <div class="mt-1 truncate text-xs text-neutral-600 dark:text-neutral-400">
                                    {{ Str::before($deployment->commitMessage(), "\n") }}
                                </div>
                            @endif
                        </div>

                        <div class="hidden min-w-0 text-sm md:block">
                            <div class="truncate text-black dark:text-white">{{ $project?->name ?? 'Unknown project' }}</div>
                            <div class="truncate text-xs text-neutral-500 dark:text-neutral-400">{{ $environment?->name }}</div>
                        </div>

                        <div class="hidden min-w-0 text-sm md:block">
                            <div class="truncate text-black dark:text-white">{{ $deployment->server_name ?? 'Unknown server' }}</div>
                        </div>

                        <div class="hidden min-w-0 text-sm md:block">
                            <div class="truncate text-neutral-600 dark:text-neutral-400">{{ $this->sourceName($application) }}</div>
                        </div>

                        <div class="flex justify-end md:justify-start">
                            <span @class([
                                'inline-flex h-5 max-w-full items-center rounded-sm border px-1.5 text-xs font-medium leading-4',
                                'border-green-200 bg-green-50 text-green-800 dark:border-green-900 dark:bg-green-950/30 dark:text-green-300' => $deployment->status === 'finished',
                                'border-yellow-300 bg-yellow-50 text-yellow-900 dark:border-yellow-800 dark:bg-yellow-950/30 dark:text-yellow-200' => in_array($deployment->status, ['queued', 'in_progress'], true),
                                'border-red-300 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300' => $deployment->status === 'failed',
                                'border-neutral-200 bg-neutral-100 text-black dark:border-coolgray-300 dark:bg-coolgray-200 dark:text-white' => $deployment->status === 'cancelled-by-user',
                            ])>
                                {{ $this->statusLabel($deployment->status) }}
                            </span>
                        </div>
                    </a>
                @empty
                    <div class="px-4 py-10 text-center text-sm text-neutral-500 dark:text-neutral-400">
                        No deployments found.
                    </div>
                @endforelse
            </div>
        </div>

        @if ($deployments->hasPages())
            <div>
                {{ $deployments->links() }}
            </div>
        @endif
    </div>
</div>
