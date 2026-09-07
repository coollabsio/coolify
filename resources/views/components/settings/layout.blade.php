@php
    $settingsMenuSections = [
        'Configuration' => [
            ['label' => 'General', 'route' => 'settings.index', 'icon' => 'settings'],
            ['label' => 'Advanced', 'route' => 'settings.advanced', 'icon' => 'grid'],
            ['label' => 'Updates', 'route' => 'settings.updates', 'icon' => 'refresh3'],
        ],
        'Instance' => [
            ['label' => 'Backup', 'route' => 'settings.backup', 'icon' => 'database'],
            ['label' => 'Email', 'route' => 'settings.email', 'icon' => 'mail'],
            ['label' => 'Authentication', 'route' => 'settings.oauth', 'icon' => 'keys'],
            ['label' => 'Scheduled Jobs', 'route' => 'settings.scheduled-jobs', 'icon' => 'calendar'],
        ],
    ];
@endphp

<section class="application-settings-workspace w-full max-w-none">
    <header class="settings-mobile-header xl:hidden">
        <h1 class="settings-mobile-title">Instance Settings</h1>
        <p class="settings-mobile-description">Configure global settings for this Coolify instance.</p>
    </header>
    <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <aside class="application-settings-navigation min-w-0 xl:self-start">
            <nav aria-label="Instance settings"
                class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
                @foreach ($settingsMenuSections as $section => $menuItems)
                    <div @class([
                        'nav-section col-span-full hidden xl:block',
                        'mt-5 border-t border-neutral-200 pt-4 dark:border-white/[0.06]' => !$loop->first,
                    ])>{{ $section }}</div>
                    @foreach ($menuItems as $menuItem)
                        <a wire:key="instance-settings-{{ str($menuItem['label'])->slug() }}"
                            @class(['menu-item', 'menu-item-active' => request()->routeIs($menuItem['route'])])
                            {{ wireNavigate() }} href="{{ route($menuItem['route']) }}">
                            <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                            <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                        </a>
                        @if ($menuItem['route'] === 'settings.oauth' && isset($submenu))
                            <div class="col-span-full ml-5 border-l border-neutral-200 pl-2 dark:border-white/[0.08]">
                                {{ $submenu }}
                            </div>
                        @endif
                    @endforeach
                @endforeach
            </nav>
        </aside>

        <div class="min-w-0">
            {{ $slot }}
        </div>
    </div>
</section>
