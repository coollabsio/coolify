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
            'icon' => 'danger',
        ],
    ])->filter();
@endphp

<x-main-nav-menu back-label="Destinations" back-title="Back to Integrations" :name="$destination->name"
    data-settings-search-items="{{ json_encode(settingsSearchItems(['Destination' => $destinationMenuItems], $destinationRouteParameters)) }}">
    <nav aria-label="Destination settings"
        class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
        @foreach ($destinationMenuItems as $menuItem)
            @if ($menuItem['label'] === 'Danger Zone')
                <div class="col-span-full my-2 hidden border-t border-neutral-200 xl:block dark:border-white/[0.06]" aria-hidden="true"></div>
            @endif
            <a wire:key="destination-settings-{{ str($menuItem['label'])->slug() }}"
                @class(['menu-item', 'menu-item-active' => $menuItem['active']])
                {{ wireNavigate() }} href="{{ route($menuItem['route'], $destinationRouteParameters) }}">
                <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                <span class="menu-item-label">{{ $menuItem['label'] }}</span>
            </a>
        @endforeach
    </nav>
</x-main-nav-menu>
