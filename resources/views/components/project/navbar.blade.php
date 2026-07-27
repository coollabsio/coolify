@props([
    'project',
    'environment' => null,
])

@php
    $projectParameters = ['project_uuid' => $project->uuid];
    $environmentParameters = $environment
        ? [...$projectParameters, 'environment_uuid' => $environment->uuid]
        : [];

    $items = $environment
        ? [
            ['label' => 'Resources', 'route' => 'project.resource.index', 'active' => request()->routeIs('project.resource.index'), 'icon' => 'grid'],
        ]
        : [];

    $routeParameters = $environment ? $environmentParameters : $projectParameters;
@endphp

@if ($environment)
<nav class="mb-6 w-full lg:mb-0">
    <div class="flex w-full items-center justify-between gap-4 lg:fixed lg:top-12 lg:right-0 lg:z-30 lg:h-12 lg:w-auto lg:border-b lg:border-neutral-200 lg:bg-white/95 lg:pr-4 lg:pl-2 lg:backdrop-blur lg:transition-[left] lg:duration-200 lg:dark:border-white/[0.06] lg:dark:bg-panel/95"
        :class="[typeof collapsed !== 'undefined' && collapsed ? 'lg:left-16' : 'lg:left-56']">
        <div
            class="flex min-w-0 items-center gap-0.5 overflow-x-auto rounded-[10px] border border-neutral-200 bg-neutral-100 p-1 dark:border-white/[0.07] dark:bg-white/[0.035]">
            @foreach ($items as $item)
                <a @class([
                    'app-tab shrink-0',
                    'bg-coollabs/10 text-coollabs shadow-sm ring-1 ring-coollabs/25 hover:bg-coollabs/15 dark:bg-warning/15 dark:text-warning dark:ring-warning/25 dark:hover:bg-warning/20' => $item['active'],
                ])
                    {{ wireNavigate() }} href="{{ route($item['route'], $routeParameters) }}">
                    <x-reicon :name="$item['icon']" class="size-3.5" />
                    {{ $item['label'] }}
                </a>
            @endforeach
        </div>

        @isset($actions)
            <div class="flex shrink-0 items-center gap-2">
                {{ $actions }}
            </div>
        @endisset
        @can('createAnyResource')
            <div class="flex shrink-0 items-center gap-2">
                <a href="{{ route('project.resource.create', $environmentParameters) }}" {{ wireNavigate() }}
                    class="button bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!">
                    <x-reicon name="plus" class="size-3.5" />
                    New resource
                </a>
            </div>
        @endcan
    </div>

    <div class="hidden lg:block lg:h-10" aria-hidden="true"></div>
</nav>
@endif
