@props([
    'section',
    'parameters' => [],
    'title' => null,
    'subtitle' => null,
    // When false (default), hide the in-flow H1 once the desktop shell is active
    // (lg+: main sidebar + fixed layer-2 tabs). Below lg the mobile topbar is used
    // and the page title stays visible. Pass true for resource-detail names.
    'titleOnDesktop' => false,
    'mobileTitleOnly' => false,
])

@php
    $items = match ($section) {
        'team' => [
            ['label' => 'General', 'route' => 'team.index', 'active' => request()->routeIs('team.index', 'team.member.index', 'team.admin-view', 'team.danger-zone')],
        ],
        'profile' => [
            ['label' => 'General', 'route' => 'profile', 'active' => request()->routeIs('profile')],
            ['label' => 'Appearance', 'route' => 'profile.appearance', 'active' => request()->routeIs('profile.appearance')],
        ],
        'notifications' => [
            ['label' => 'Email', 'route' => 'notifications.email', 'active' => request()->routeIs('notifications.*')],
        ],
        'security' => [
            ['label' => 'Private Keys', 'route' => 'security.private-key.index', 'active' => request()->routeIs('security.*')],
        ],
        'settings' => [
            ['label' => 'General', 'route' => 'settings.index', 'active' => request()->routeIs('settings.*')],
        ],
        'source' => [
            [
                'label' => 'General',
                'route' => 'source.github.show',
                'active' => request()->routeIs('source.github.show', 'source.github.permissions', 'source.github.resources', 'source.github.danger'),
            ],
        ],
        'destination' => [
            ['label' => 'General', 'route' => 'destination.show', 'active' => request()->routeIs('destination.show', 'destination.resources', 'destination.danger')],
        ],
        'storage' => [
            [
                'label' => 'General',
                'route' => 'storage.show',
                'active' => request()->routeIs('storage.show', 'storage.danger', 'storage.resources'),
            ],
        ],
        'subscription' => [
            [
                'label' => 'Plan',
                'route' => 'subscription.show',
                'active' => request()->routeIs('subscription.show'),
                'icon' => 'subscription',
                // Unsubscribed accounts cannot open /subscription (middleware forces /subscription/new).
                'visible' => isSubscriptionActive() || isSubscriptionOnGracePeriod(),
            ],
            [
                'label' => 'Pricing',
                'route' => 'subscription.index',
                'active' => request()->routeIs('subscription.index'),
                'icon' => 'dashboard',
                // Active subscriptions are redirected away from pricing by middleware.
                'visible' => ! isSubscriptionActive(),
            ],
        ],
        default => [],
    };

    $items = array_values(array_filter($items, fn (array $item): bool => $item['visible'] ?? true));
    // A single self-link tab (e.g. only "Pricing" on /subscription/new) is not navigation.
    $showTabs = count($items) >= 2;
    $hasTitle = filled($title);
    $hasActions = isset($actions);
    $showNav = $showTabs || $hasActions;
@endphp

@if ($hasTitle)
    <div class="flex flex-col">
        <header @class([
            'order-1 mb-4 flex min-w-0 items-start justify-between gap-4 lg:order-2',
            'lg:mb-8' => $titleOnDesktop || ! $showNav,
            // Hide with the desktop chrome (sidebar + fixed tabs), not xl.
            // Keep title on desktop when there is no fixed layer-2 tab strip.
            'lg:hidden' => $mobileTitleOnly || (! $titleOnDesktop && $showNav),
        ])>
            <div class="min-w-0 flex-1">
                <div class="flex min-w-0 flex-wrap items-center gap-2">
                    <h1 class="min-w-0 truncate text-[24px]! leading-7! font-semibold! tracking-tight!">{{ $title }}</h1>
                    @isset($titleMeta)
                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            {{ $titleMeta }}
                        </div>
                    @endisset
                </div>
                @if (filled($subtitle))
                    <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">{{ $subtitle }}</p>
                @endif
            </div>
            @isset($titleActions)
                <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
                    {{ $titleActions }}
                </div>
            @endisset
        </header>
        @if ($showNav)
        <div class="order-2 lg:order-1">
@endif
@endif

@if ($showNav)
<nav class="mb-6 w-full lg:mb-0">
    <div class="w-full lg:fixed lg:top-12 lg:right-0 lg:z-30 lg:h-12 lg:w-auto lg:border-b lg:border-neutral-200 lg:bg-white/95 lg:pr-4 lg:pl-2 lg:backdrop-blur lg:transition-[left] lg:duration-200 lg:dark:border-white/[0.06] lg:dark:bg-panel/95"
        :class="[typeof collapsed !== 'undefined' && collapsed ? 'lg:left-16' : 'lg:left-56']">
        {{-- Mobile: stack tabs then actions so long buttons never crush the tab strip.
             Desktop: single fixed row with tabs left and actions right. --}}
        <div
            class="flex w-full flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-3 lg:h-full lg:gap-4">
            @if ($showTabs)
            <div class="flex min-w-0 w-full items-center gap-0.5 overflow-x-auto rounded-[10px] border border-neutral-200 bg-neutral-100 p-1 sm:flex-1 dark:border-white/[0.07] dark:bg-white/[0.035]">
                @foreach ($items as $item)
                    <a @class([
                        'app-tab shrink-0',
                        'bg-coollabs/10 text-coollabs shadow-sm ring-1 ring-coollabs/25 hover:bg-coollabs/15 dark:bg-warning/15 dark:text-warning dark:ring-warning/25 dark:hover:bg-warning/20' => $item['active'],
                    ])
                        {{ wireNavigate() }} href="{{ route($item['route'], $parameters) }}">
                        @if ($item['icon'] ?? null)
                            <x-reicon :name="$item['icon']" class="size-3.5" />
                        @endif
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </div>
            @endif

            @isset($actions)
                <div
                    class="flex w-full shrink-0 flex-wrap items-center justify-end gap-2 sm:w-auto [&_.button]:whitespace-nowrap">
                    {{ $actions }}
                </div>
            @endisset
        </div>
    </div>

    <div class="hidden lg:block lg:h-12" aria-hidden="true"></div>
</nav>
@endif

@if ($hasTitle)
        @if ($showNav)
        </div>
        @endif
    </div>
@endif
