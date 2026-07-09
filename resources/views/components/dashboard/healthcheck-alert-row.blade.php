@props([
    'alert',
])

<div @class([
    'flex w-full items-start justify-between gap-2 p-2 border-l-2 bg-white dark:bg-coolgray-100',
    'border-error',
])>
    <div class="flex min-w-0 flex-col gap-1 text-sm">
        <div class="flex flex-wrap items-center gap-2">
            <span class="truncate font-medium dark:text-white">{{ $alert['name'] }}</span>
            <span class="inline-flex w-fit rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-600/30 dark:text-gray-300">
                {{ $alert['type_label'] }}
            </span>
        </div>
        @if ($alert['parent_name'])
            <div class="text-xs text-neutral-500">
                <span class="font-medium">Stack:</span> {{ $alert['parent_name'] }}
            </div>
        @endif
        @if ($alert['project_name'] || $alert['environment_name'])
            <div class="text-xs text-neutral-500">
                @if ($alert['project_name'])
                    <span class="font-medium">Project:</span> {{ $alert['project_name'] }}
                @endif
                @if ($alert['project_name'] && $alert['environment_name'])
                    <span class="mx-1">·</span>
                @endif
                @if ($alert['environment_name'])
                    <span class="font-medium">Environment:</span> {{ $alert['environment_name'] }}
                @endif
            </div>
        @endif
        <span class="inline-flex w-fit rounded-md bg-red-100 px-3 py-1 text-xs font-medium text-red-800 shadow-xs dark:bg-red-900/30 dark:text-red-200">
            Exited
        </span>
    </div>
    <div class="flex shrink-0 flex-col items-end gap-1">
        @if ($alert['url'])
            <a href="{{ $alert['url'] }}" {{ wireNavigate() }}
                class="text-xs font-medium underline dark:text-white hover:opacity-80">
                View resource
            </a>
        @endif
        @if ($alert['healthcheck_url'])
            <a href="{{ $alert['healthcheck_url'] }}" {{ wireNavigate() }}
                class="text-xs font-medium underline dark:text-white hover:opacity-80">
                Enable healthcheck
            </a>
        @endif
    </div>
</div>
