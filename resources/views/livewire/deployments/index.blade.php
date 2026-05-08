<div>
    <x-slot:title>Deployments | Coolify</x-slot>

    <div class="flex items-center justify-between">
        <h1>Deployments</h1>
    </div>

    <div class="flex flex-wrap items-center gap-2 pb-4" wire:poll.5000ms="loadDeployments">
        <x-forms.select wire:model.live="filterProject">
            <option value="">All Projects</option>
            @foreach ($projects as $project)
                <option value="{{ $project->uuid }}">{{ $project->name }}</option>
            @endforeach
        </x-forms.select>

        @if ($servers->count() > 1)
            <x-forms.select wire:model.live="filterServer">
                <option value="">All Servers</option>
                @foreach ($servers as $server)
                    <option value="{{ $server->uuid }}">{{ $server->name }}</option>
                @endforeach
            </x-forms.select>
        @endif

        @if ($sources->count() > 1)
            <x-forms.select wire:model.live="filterSource">
                <option value="">All Sources</option>
                @foreach ($sources as $source)
                    <option value="{{ $source->id }}">{{ $source->name }}</option>
                @endforeach
            </x-forms.select>
        @endif

        <x-forms.select wire:model.live="filterStatus">
            <option value="">All Statuses</option>
            <option value="queued">Queued</option>
            <option value="in_progress">In Progress</option>
            <option value="finished">Success</option>
            <option value="failed">Failed</option>
            <option value="cancelled-by-user">Cancelled</option>
        </x-forms.select>

        @if ($filterProject || $filterServer || $filterSource || $filterStatus)
            <x-forms.button wire:click="clearFilters">Clear Filters</x-forms.button>
        @endif

        <span class="text-sm text-gray-500 ml-auto">{{ $totalCount }} total</span>
    </div>

    <div class="flex flex-col gap-2">
        @forelse ($deployments as $deployment)
            <a href="{{ $deployment->deployment_url }}" @class([
                'p-3 border-l-2 bg-white dark:bg-coolgray-100 rounded-lg transition-all hover:shadow-md',
                'border-blue-500/50' => $deployment->status === 'in_progress',
                'border-purple-500/50' => $deployment->status === 'queued',
                'border-red-500' => $deployment->status === 'failed',
                'border-green-500' => $deployment->status === 'finished',
                'border-gray-400' => $deployment->status === 'cancelled-by-user',
            ])>
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span @class([
                            'px-2 py-0.5 rounded text-xs font-medium',
                            'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300' => $deployment->status === 'in_progress',
                            'bg-purple-100 text-purple-700 dark:bg-purple-500/20 dark:text-purple-300' => $deployment->status === 'queued',
                            'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-300' => $deployment->status === 'finished',
                            'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300' => $deployment->status === 'failed',
                            'bg-gray-100 text-gray-600 dark:bg-gray-500/20 dark:text-gray-300' => $deployment->status === 'cancelled-by-user',
                        ])>
                            {{ str_replace(['-', '_'], ' ', ucfirst($deployment->status)) }}
                        </span>
                        <span class="font-medium">{{ $deployment->application_name }}</span>
                    </div>
                    <div class="text-xs text-gray-500">
                        {{ $deployment->created_at->diffForHumans() }}
                    </div>
                </div>
                <div class="flex items-center gap-4 mt-1 text-xs text-gray-500">
                    <span>
                        {{ data_get($deployment, 'application.environment.project.name') }} /
                        {{ data_get($deployment, 'application.environment.name') }}
                    </span>
                    <span>{{ $deployment->server_name }}</span>
                    @if ($deployment->commit)
                        <span>{{ substr($deployment->commit, 0, 7) }}</span>
                    @endif
                    @if ($deployment->is_webhook)
                        <span class="bg-gray-200 dark:bg-coolgray-200 px-1.5 py-0.5 rounded text-xs dark:text-white">Webhook</span>
                    @elseif ($deployment->rollback)
                        <span class="bg-gray-200 dark:bg-coolgray-200 px-1.5 py-0.5 rounded text-xs dark:text-white">Rollback</span>
                    @elseif ($deployment->pull_request_id)
                        <span class="bg-gray-200 dark:bg-coolgray-200 px-1.5 py-0.5 rounded text-xs dark:text-white">PR #{{ $deployment->pull_request_id }}</span>
                    @else
                        <span class="bg-gray-200 dark:bg-coolgray-200 px-1.5 py-0.5 rounded text-xs dark:text-white">Manual</span>
                    @endif
                    @if ($deployment->finished_at)
                        <span>{{ \Carbon\Carbon::parse($deployment->created_at)->diffForHumans(\Carbon\Carbon::parse($deployment->finished_at), true) }}</span>
                    @elseif ($deployment->status === 'in_progress')
                        <span class="text-blue-500">Running for {{ \Carbon\Carbon::parse($deployment->created_at)->diffForHumans(now(), true) }}</span>
                    @endif
                </div>
                @if ($deployment->commit_message)
                    <div class="mt-1 text-xs text-gray-600 dark:text-gray-400 truncate max-w-2xl">
                        {{ $deployment->commit_message }}
                    </div>
                @endif
            </a>
        @empty
            <div class="text-center py-10 text-gray-500">
                <svg class="mx-auto h-12 w-12 text-gray-300 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
                </svg>
                <p class="text-lg font-medium">No deployments found</p>
                <p class="mt-1">Deploy your first application to see it here.</p>
            </div>
        @endforelse

        @if ($hasMorePages)
            <div class="text-center pt-2">
                <x-forms.button wire:click="loadMore">Load More</x-forms.button>
            </div>
        @endif
    </div>
</div>
