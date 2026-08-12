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
    <div class="flex w-full items-center lg:fixed lg:top-12 lg:right-0 lg:z-30 lg:h-12 lg:w-auto lg:border-b lg:border-neutral-200 lg:bg-white/95 lg:pr-4 lg:pl-2 lg:backdrop-blur lg:transition-[left] lg:duration-200 lg:dark:border-white/[0.06] lg:dark:bg-panel/95"
        :class="[typeof collapsed !== 'undefined' && collapsed ? 'lg:left-16' : 'lg:left-56']">
        <div
            class="resource-heading-navbar application-heading-actions flex w-full min-w-0 items-center justify-between gap-2 overflow-visible rounded-[10px] border border-neutral-200 bg-neutral-100 p-1 dark:border-white/[0.07] dark:bg-white/[0.035]">
            <x-resource-heading-tabs class="min-w-0">
                @foreach ($items as $item)
                    <a @class([
                        'app-tab shrink-0',
                        'app-tab-active' => $item['active'],
                    ])
                        @if ($item['active']) aria-current="page" @endif
                        {{ wireNavigate() }} href="{{ route($item['route'], $routeParameters) }}">
                        <x-reicon :name="$item['icon']" class="size-3.5" />
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </x-resource-heading-tabs>

            <div class="resource-heading-actions flex shrink-0 items-center gap-0.5 border-l border-neutral-200 pl-1 dark:border-white/[0.08]">
                @isset($actions)
                    {{ $actions }}
                @endisset
                @can('createAnyResource')
                    <a href="{{ route('project.resource.create', $environmentParameters) }}" {{ wireNavigate() }}
                        class="button button-highlighted">
                        <x-reicon name="plus" class="size-3.5" />
                        New resource
                    </a>
                @endcan
            </div>
        </div>
    </div>

    <div class="hidden lg:block lg:h-12" aria-hidden="true"></div>
</nav>
@endif
