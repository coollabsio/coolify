@php
    $sharedVariablesMenuItems = [
        ['label' => 'Overview', 'route' => 'shared-variables.index', 'icon' => 'dashboard', 'active' => request()->routeIs('shared-variables.index')],
        ['label' => 'Team', 'route' => 'shared-variables.team.index', 'icon' => 'teams', 'active' => request()->routeIs('shared-variables.team.*')],
        ['label' => 'Projects', 'route' => 'shared-variables.project.index', 'icon' => 'projects', 'active' => request()->routeIs('shared-variables.project.*')],
        ['label' => 'Environments', 'route' => 'shared-variables.environment.index', 'icon' => 'layers', 'active' => request()->routeIs('shared-variables.environment.*')],
        ['label' => 'Servers', 'route' => 'shared-variables.server.index', 'icon' => 'servers', 'active' => request()->routeIs('shared-variables.server.*')],
    ];
@endphp

<section class="w-full max-w-none">
    <header class="mb-6 xl:hidden">
        <h1 class="text-[24px]! leading-7! font-semibold! tracking-tight!">Shared variables</h1>
        <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">Reusable environment variables across resources</p>
    </header>

    <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
        <aside class="min-w-0 xl:self-start">
            <nav aria-label="Shared variables"
                class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 lg:grid-cols-5 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
                @foreach ($sharedVariablesMenuItems as $menuItem)
                    <a wire:key="shared-variables-{{ str($menuItem['label'])->slug() }}"
                        @class(['menu-item', 'menu-item-active' => $menuItem['active']])
                        {{ wireNavigate() }} href="{{ route($menuItem['route']) }}">
                        <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                        <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                    </a>
                @endforeach
            </nav>
        </aside>

        <div class="min-w-0">
            {{ $slot }}
        </div>
    </div>
</section>
