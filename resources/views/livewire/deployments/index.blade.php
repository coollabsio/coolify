<div>
    <x-slot:title>Deployments | Coolify</x-slot:title>

    <div class="flex flex-col gap-4 pb-10" @if ($deployments->currentPage() === 1) wire:poll.5000ms @endif>
        <div class="flex flex-wrap items-center gap-4">
            <h1>Deployments <span class="text-xs">({{ $deployments->total() }})</span></h1>
            @if ($deployments->total() > 0)
                <div class="flex items-center gap-2">
                    <x-forms.button type="button" :disabled="$deployments->onFirstPage()" wire:click="previousPage">
                        <svg class="w-4 h-4" viewBox="0 0 24 24">
                            <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="2" d="m14 6l-6 6l6 6z" />
                        </svg>
                    </x-forms.button>
                    <span class="px-2 text-sm text-gray-600 dark:text-gray-400">
                        Page {{ $deployments->currentPage() }} of {{ $deployments->lastPage() }}
                    </span>
                    <x-forms.button type="button" :disabled="! $deployments->hasMorePages()" wire:click="nextPage">
                        <svg class="w-4 h-4" viewBox="0 0 24 24">
                            <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="2" d="m10 18l6-6l-6-6z" />
                        </svg>
                    </x-forms.button>
                </div>
            @endif
        </div>

        <div class="flex flex-col gap-3 md:flex-row md:items-end">
            <div class="w-full md:w-48">
                <x-forms.select id="deployment_type" label="Type" wire:model.live="deployment_type">
                    @foreach ($this->deploymentTypeOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-forms.select>
            </div>
            <div class="w-full md:w-48">
                <x-forms.select id="status" label="Status" wire:model.live="status">
                    @foreach ($this->statusOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-forms.select>
            </div>
        </div>

        <div class="flex flex-col gap-2">
            @forelse ($deployments as $deployment)
                @php
                    $application = data_get($deployment, 'application');
                    $environment = data_get($application, 'environment');
                    $project = data_get($environment, 'project');
                    $status = data_get($deployment, 'status');
                    $statusText = match ($status) {
                        'finished' => 'Success',
                        'in_progress' => 'In Progress',
                        'cancelled-by-user' => 'Cancelled',
                        'queued' => 'Queued',
                        default => ucfirst((string) $status),
                    };
                    $triggerText = match (true) {
                        (bool) data_get($deployment, 'is_webhook') => data_get($deployment, 'pull_request_id')
                            ? 'Webhook | Pull Request #' . data_get($deployment, 'pull_request_id')
                            : 'Webhook',
                        (bool) data_get($deployment, 'pull_request_id') => 'Pull Request #' . data_get($deployment, 'pull_request_id'),
                        (bool) data_get($deployment, 'rollback') => 'Rollback',
                        (bool) data_get($deployment, 'is_api') => 'API',
                        default => 'Manual',
                    };
                    $deploymentUrl = data_get($deployment, 'deployment_url');
                    $server = $servers->get(data_get($deployment, 'server_id'));
                @endphp

                <div data-deployment-uuid="{{ data_get($deployment, 'deployment_uuid') }}"
                    @if ($deploymentUrl) x-on:click="if (!$event.target.closest('a')) {
                            const url = @js($deploymentUrl);
                            window.Livewire?.navigate ? window.Livewire.navigate(url) : window.location.href = url;
                        }" @endif
                    @class([
                    'p-3 border-l-2 bg-white dark:bg-coolgray-100',
                    'cursor-pointer' => $deploymentUrl,
                    'border-blue-500/50 border-dashed' => $status === 'in_progress',
                    'border-purple-500/50 border-dashed' => $status === 'queued',
                    'border-white border-dashed' => $status === 'cancelled-by-user',
                    'border-error' => $status === 'failed',
                    'border-success' => $status === 'finished',
                ])>
                    <div class="flex flex-col gap-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <span @class([
                                'px-3 py-1 rounded-md text-xs font-medium shadow-xs',
                                'bg-blue-100/80 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300' => $status === 'in_progress',
                                'bg-purple-100/80 text-purple-700 dark:bg-purple-500/20 dark:text-purple-300' => $status === 'queued',
                                'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200' => $status === 'failed',
                                'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200' => $status === 'finished',
                                'bg-gray-100 text-gray-700 dark:bg-gray-600/30 dark:text-gray-300' => $status === 'cancelled-by-user',
                            ])>{{ $statusText }}</span>
                            <span
                                class="bg-gray-200/70 dark:bg-gray-600/20 px-2 py-0.5 rounded-md text-xs text-gray-800 dark:text-gray-100 border border-gray-400/30">
                                {{ $triggerText }}
                            </span>
                        </div>

                        <div class="flex flex-col gap-1">
                            <div class="font-medium dark:text-white">
                                {{ data_get($deployment, 'application_name') ?? data_get($application, 'name') ?? 'Unknown application' }}
                            </div>
                            <div class="text-sm text-gray-600 dark:text-gray-400">
                                {{ data_get($project, 'name') ?? 'Unknown project' }}
                                /
                                {{ data_get($environment, 'name') ?? 'Unknown environment' }}
                                @if (data_get($deployment, 'server_name'))
                                    <span class="px-1">/</span>
                                    {{ data_get($deployment, 'server_name') }}
                                @endif
                            </div>
                        </div>

                        @if (data_get($deployment, 'commit'))
                            <div class="text-sm text-gray-600 dark:text-gray-400">
                                <span class="font-medium">Commit:</span>
                                @if ($application)
                                    <a href="{{ $application->gitCommitLink(data_get($deployment, 'commit')) }}" target="_blank"
                                        rel="noopener noreferrer" class="underline">
                                        {{ substr(data_get($deployment, 'commit'), 0, 7) }}
                                    </a>
                                @else
                                    {{ substr(data_get($deployment, 'commit'), 0, 7) }}
                                @endif
                                @if ($deployment->commitMessage())
                                    <span>-</span>
                                    <span>{{ Str::before($deployment->commitMessage(), "\n") }}</span>
                                @endif
                            </div>
                        @endif

                        <div class="text-sm text-gray-600 dark:text-gray-400">
                            Started:
                            {{ formatDateInServerTimezone(data_get($deployment, 'created_at'), $server) }}
                            @if ($status !== 'queued')
                                @if ($status === 'in_progress')
                                    <br>Running for:
                                    {{ calculateDuration(data_get($deployment, 'created_at'), now()) }}
                                @elseif (data_get($deployment, 'finished_at'))
                                    <br>Ended:
                                    {{ formatDateInServerTimezone(data_get($deployment, 'finished_at'), $server) }}
                                    <br>Duration:
                                    {{ calculateDuration(data_get($deployment, 'created_at'), data_get($deployment, 'finished_at')) }}
                                @endif
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div>No deployments found</div>
            @endforelse
        </div>

    </div>
</div>
