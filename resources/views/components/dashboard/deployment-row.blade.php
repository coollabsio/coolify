@props([
    'deployment',
    'application',
])

@php
    $project = $deployment->application?->environment?->project;
    $environment = $deployment->application?->environment;
    $deploymentsIndexUrl = $project && $environment && $application
        ? route('project.application.deployment.index', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'application_uuid' => $application->uuid,
        ])
        : null;
    $statusText = match (data_get($deployment, 'status')) {
        'finished' => 'Success',
        'in_progress' => 'In Progress',
        'cancelled-by-user' => 'Cancelled',
        'queued' => 'Queued',
        default => ucfirst(data_get($deployment, 'status')),
    };
@endphp

<div @class([
    'w-full p-2 border-l-2 bg-white dark:bg-coolgray-100',
    'border-blue-500/50 border-dashed' => data_get($deployment, 'status') === 'in_progress',
    'border-purple-500/50 border-dashed' => data_get($deployment, 'status') === 'queued',
    'border-white border-dashed' => data_get($deployment, 'status') === 'cancelled-by-user',
    'border-error' => data_get($deployment, 'status') === 'failed',
    'border-success' => data_get($deployment, 'status') === 'finished',
])>
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="flex flex-col gap-2 text-sm">
            <div class="flex flex-col gap-0.5">
                @if ($project)
                    <div>
                        <span class="font-medium text-neutral-500">Project:</span>
                        <span class="dark:text-white">{{ $project->name }}</span>
                    </div>
                @endif
                <div>
                    <span class="font-medium text-neutral-500">Application:</span>
                    <span class="dark:text-white">{{ data_get($deployment, 'application_name') }}</span>
                </div>
            </div>
            <span @class([
                'inline-flex w-fit px-3 py-1 rounded-md text-xs font-medium shadow-xs',
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
                {{ $statusText }}
            </span>
        </div>
        @if ($deploymentsIndexUrl)
            <a href="{{ $deploymentsIndexUrl }}" {{ wireNavigate() }}
                class="text-xs font-medium underline dark:text-white hover:opacity-80">
                View deployments
            </a>
        @endif
    </div>
</div>
