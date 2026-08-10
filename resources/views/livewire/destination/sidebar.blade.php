@php
    $destinationRouteParameters = ['destination_uuid' => $destination->uuid];
    $destinationMenuItems = collect([
        [
            'label' => 'General',
            'route' => 'destination.show',
            'active' => request()->routeIs('destination.show'),
            'icon' => 'settings',
        ],
        $destination->getMorphClass() === 'App\\Models\\StandaloneDocker' ? [
            'label' => 'Resources',
            'route' => 'destination.resources',
            'active' => request()->routeIs('destination.resources'),
            'icon' => 'grid',
        ] : null,
        [
            'label' => 'Danger Zone',
            'route' => 'destination.danger',
            'active' => request()->routeIs('destination.danger'),
            'icon' => 'shield-alert',
        ],
    ])->filter();
@endphp

<aside class="application-settings-navigation min-w-0 xl:self-start">
    <nav aria-label="Destination settings"
        class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
        <div class="nav-section hidden xl:block">Settings</div>
        @foreach ($destinationMenuItems as $menuItem)
            <a wire:key="destination-settings-{{ str($menuItem['label'])->slug() }}"
                @class(['menu-item', 'menu-item-active' => $menuItem['active']])
                {{ wireNavigate() }} href="{{ route($menuItem['route'], $destinationRouteParameters) }}">
                <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                <span class="menu-item-label">{{ $menuItem['label'] }}</span>
            </a>
        @endforeach
    </nav>
</aside>
