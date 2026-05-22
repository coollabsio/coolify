<div>
    <x-slot:title>Deployments | Coolify</x-slot>
    <h1>Deployments</h1>

    <div class="flex flex-col gap-2 pb-10" wire:poll.5000ms>
        <div class="flex flex-col gap-2 xl:flex-row xl:items-end">
            @if ($projects?->count() > 1)
                <x-forms.select id="projectUuid" label="Project" wire:model.live="projectUuid">
                    <option value="">All projects</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->uuid }}">{{ $project->name }}</option>
                    @endforeach
                </x-forms.select>
            @endif

            @if ($servers?->count() > 1)
                <x-forms.select id="serverUuid" label="Server" wire:model.live="serverUuid">
                    <option value="">All servers</option>
                    @foreach ($servers as $server)
                        <option value="{{ $server->uuid }}">{{ $server->name }}</option>
                    @endforeach
                </x-forms.select>
            @endif

            @if (($availableSources?->count() ?? 0) > 1)
                <x-forms.select id="source" label="Source" wire:model.live="source">
                    <option value="">All sources</option>
                    @foreach ($availableSources as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-forms.select>
            @endif

            <x-forms.select id="status" label="Status" wire:model.live="status">
                <option value="">All statuses</option>
                @foreach ($availableStatuses as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-forms.select>

            <x-forms.select id="perPage" label="Per page" wire:model.live="perPage">
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </x-forms.select>
        </div>

        <div class="flex flex-col gap-2">
            @forelse ($deployments as $deployment)
                @php
                    $application = $deployment->application;
                @endphp
                <div @class([
                    'p-2 border-l-2 bg-white dark:bg-coolgray-100',
                    'border-blue-500/50 border-dashed' => data_get($deployment, 'status') === 'in_progress',
                    'border-purple-500/50 border-dashed' => data_get($deployment, 'status') === 'queued',
                    'border-white border-dashed' => data_get($deployment, 'status') === 'cancelled-by-user',
                    'border-error' => data_get($deployment, 'status') === 'failed',
                    'border-success' => data_get($deployment, 'status') === 'finished',
                ])>
                    <a href="{{ data_get($deployment, 'deployment_url') }}" {{ wireNavigate() }} class="block">
                        <div class="flex flex-col">
                            <div class="flex items-center gap-2 mb-2">
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
                                @if (data_get($deployment, 'server_name'))
                                    <span class="text-xs text-gray-600 dark:text-gray-400">
                                        {{ data_get($deployment, 'server_name') }}
                                    </span>
                                @endif
                            </div>

                            <div class="flex flex-col gap-1">
                                <div class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                    {{ data_get($deployment, 'application_name') }}
                                    @if ($application?->project())
                                        <span class="text-gray-600 dark:text-gray-400 font-normal">
                                            — {{ $application->project()->name }}
                                        </span>
                                    @endif
                                </div>

                                <div class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400">
                                    @if (data_get($deployment, 'commit'))
                                        <span class="font-mono">{{ Str::limit(data_get($deployment, 'commit'), 12, '') }}</span>
                                    @endif
                                    <span class="bg-gray-200/70 dark:bg-gray-600/20 px-2 py-0.5 rounded-md text-xs text-gray-800 dark:text-gray-100 border border-gray-400/30">
                                        @if (data_get($deployment, 'is_webhook'))
                                            Webhook
                                            @if (data_get($deployment, 'pull_request_id'))
                                                | Pull Request #{{ data_get($deployment, 'pull_request_id') }}
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
                                    <span class="text-gray-500 dark:text-gray-500">
                                        {{ optional(data_get($deployment, 'created_at'))->diffForHumans() }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
            @empty
                <div>No deployments found.</div>
            @endforelse
        </div>

        <div>
            {{ $deployments->links() }}
        </div>
    </div>
</div>

