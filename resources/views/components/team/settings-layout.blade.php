@php
    $teamMenuItems = collect([
        [
            'label' => 'General',
            'route' => 'team.index',
            'active' => request()->routeIs('team.index'),
            'icon' => 'settings',
        ],
        [
            'label' => 'Members',
            'route' => 'team.member.index',
            'active' => request()->routeIs('team.member.index'),
            'icon' => 'teams',
        ],
        isInstanceAdmin() ? [
            'label' => 'Admin View',
            'route' => 'team.admin-view',
            'active' => request()->routeIs('team.admin-view'),
            'icon' => 'admin',
        ] : null,
        [
            'label' => 'Danger Zone',
            'route' => 'team.danger-zone',
            'active' => request()->routeIs('team.danger-zone'),
            'icon' => 'shield-alert',
            'sectionStart' => true,
        ],
    ])->filter();
@endphp

<section class="application-settings-workspace w-full max-w-none">
    <header class="settings-mobile-header xl:hidden">
        <h1 class="settings-mobile-title">Team</h1>
        <p class="settings-mobile-description">Manage your team, members, and access settings.</p>
    </header>
    <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <aside class="application-settings-navigation min-w-0 xl:self-start">
            <nav aria-label="Team settings"
                class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
                <div class="nav-section hidden xl:block">Team</div>
                @foreach ($teamMenuItems as $menuItem)
                    @if ($menuItem['sectionStart'] ?? false)
                        <div class="col-span-full my-2 hidden border-t border-neutral-200 xl:block dark:border-white/[0.06]"
                            aria-hidden="true"></div>
                    @endif
                    <a wire:key="team-settings-{{ str($menuItem['label'])->slug() }}"
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
