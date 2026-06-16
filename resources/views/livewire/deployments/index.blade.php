<div>
    <x-slot:title>
        Deployments | Coolify
    </x-slot>
    <div class="flex items-center gap-2">
        <h1>Deployments</h1>
    </div>
    <div class="subtitle">Your last 10 deployments are shown here.</div>
    <div class="flex flex-col gap-2 pb-10" wire:poll.5000ms='reloadDeployments'>
        @forelse ($deployments as $deployment)
            @php
                $application = data_get($deployment, 'application');
                $server = data_get($application, 'destination.server');
            @endphp
            <div @class([
                'p-2 border-l-2 bg-white dark:bg-coolgray-100',
                'border-blue-500/50 border-dashed' => data_get($deployment, 'status') === 'in_progress',
                'border-purple-500/50 border-dashed' => data_get($deployment, 'status') === 'queued',
                'border-white border-dashed' => data_get($deployment, 'status') === 'cancelled-by-user',
                'border-error' => data_get($deployment, 'status') === 'failed',
                'border-success' => data_get($deployment, 'status') === 'finished',
            ])>
                @if ($application)
                    <a href="{{ route('project.application.deployment.show', [
                        'project_uuid' => data_get($application, 'environment.project.uuid'),
                        'environment_uuid' => data_get($application, 'environment.uuid'),
                        'application_uuid' => data_get($application, 'uuid'),
                        'deployment_uuid' => data_get($deployment, 'deployment_uuid'),
                    ]) }}" {{ wireNavigate() }} class="block">
                @endif
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
                            <span class="font-medium">
                                {{ data_get($deployment, 'application_name', data_get($application, 'name', 'Deleted application')) }}
                            </span>
                            @if (data_get($application, 'environment.project.name'))
                                <span class="text-gray-600 dark:text-gray-400 text-sm">
                                    {{ data_get($application, 'environment.project.name') }} / {{ data_get($application, 'environment.name') }}
                                </span>
                            @endif
                        </div>
                        @if (data_get($deployment, 'status') !== 'queued')
                            <div class="text-gray-600 dark:text-gray-400 text-sm">
                                Started:
                                {{ formatDateInServerTimezone(data_get($deployment, 'created_at'), $server) }}
                                @if ($deployment->status !== 'in_progress' && $deployment->status !== 'cancelled-by-user')
                                    <br>Ended:
                                    {{ formatDateInServerTimezone(data_get($deployment, 'finished_at'), $server) }}
                                    <br>Duration:
                                    {{ calculateDuration(data_get($deployment, 'created_at'), data_get($deployment, 'finished_at')) }}
                                    <br>Finished
                                    {{ \Carbon\Carbon::parse(data_get($deployment, 'finished_at'))->diffForHumans() }}
                                @elseif($deployment->status === 'in_progress')
                                    <br>Running for:
                                    {{ calculateDuration(data_get($deployment, 'created_at'), now()) }}
                                @endif
                            </div>
                        @endif

                        <div class="text-gray-600 dark:text-gray-400 text-sm mt-2">
                            @if (data_get($deployment, 'commit') && $application)
                                <div class="flex items-center gap-2">
                                    <span class="font-medium">Commit:</span>
                                    <a href="{{ $application->gitCommitLink(data_get($deployment, 'commit')) }}"
                                        target="_blank" class="underline">
                                        {{ substr(data_get($deployment, 'commit'), 0, 7) }}
                                    </a>
                                    <span
                                        class="bg-gray-200/70 dark:bg-gray-600/20 px-2 py-0.5 rounded-md text-xs text-gray-800 dark:text-gray-100 border border-gray-400/30">
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
                                    @if ($deployment->commitMessage())
                                        <span class="text-gray-600 dark:text-gray-400">-</span>
                                        <span class="text-gray-600 dark:text-gray-400 truncate max-w-md">
                                            {{ Str::before($deployment->commitMessage(), "\n") }}
                                        </span>
                                    @endif
                                </div>
                            @endif
                        </div>

                        @if (data_get($deployment, 'server_name'))
                            <div class="text-gray-600 dark:text-gray-400 text-sm mt-2">
                                Server: {{ data_get($deployment, 'server_name') }}
                            </div>
                        @endif
                    </div>
                @if ($application)
                    </a>
                @endif
            </div>
        @empty
            <div>No deployments found</div>
        @endforelse
    </div>
</div>
