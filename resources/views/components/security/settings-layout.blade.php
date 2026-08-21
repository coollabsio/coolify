@php
    $securityMenuItems = collect([
        [
            'label' => 'Private Keys',
            'route' => 'security.private-key.index',
            'active' => request()->routeIs('security.private-key.*'),
            'icon' => 'keys',
        ],
        auth()->user()?->can('viewAny', App\Models\CloudProviderToken::class) ? [
            'label' => 'Cloud Tokens',
            'route' => 'security.cloud-tokens',
            'active' => request()->routeIs('security.cloud-tokens*'),
            'icon' => 'cloud',
        ] : null,
        auth()->user()?->can('viewAny', App\Models\CloudInitScript::class) ? [
            'label' => 'Cloud-Init Scripts',
            'route' => 'security.cloud-init-scripts',
            'active' => request()->routeIs('security.cloud-init-scripts*'),
            'icon' => 'file-content',
        ] : null,
        [
            'label' => 'API Tokens',
            'route' => 'security.api-tokens',
            'active' => request()->routeIs('security.api-tokens'),
            'icon' => 'code',
        ],
    ])->filter();
@endphp

<section class="application-settings-workspace w-full max-w-none">
    <header class="settings-mobile-header xl:hidden">
        <h1 class="settings-mobile-title">Keys & Tokens</h1>
        <p class="settings-mobile-description">Manage SSH keys, cloud credentials, and API access tokens.</p>
    </header>
    <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <aside class="application-settings-navigation min-w-0 xl:self-start">
            <nav aria-label="Keys and tokens"
                class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
                <div class="nav-section hidden xl:block">Keys & Tokens</div>
                @foreach ($securityMenuItems as $menuItem)
                    <a wire:key="security-settings-{{ str($menuItem['label'])->slug() }}"
                        @class(['menu-item', 'menu-item-active' => $menuItem['active']])
                        {{ wireNavigate() }} href="{{ route($menuItem['route']) }}">
                        <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                        <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                    </a>
                @endforeach
            </nav>
        </aside>

        <div class="min-w-0">
            @isset($actions)
                <div class="mb-3 flex min-h-8 flex-wrap items-center justify-end gap-2">
                    {{ $actions }}
                </div>
            @endisset
            {{ $slot }}
        </div>
    </div>
</section>
