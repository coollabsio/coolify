@props(['desktop' => false])

<nav @class([
    'sidebar-nav flex min-h-0 flex-col flex-1 border-r border-black/[0.06] dark:border-white/[0.04] pt-2',
    'bg-neutral-50 dark:bg-app' => $desktop,
    'bg-white dark:bg-panel' => ! $desktop,
])
    :class="collapsed ? 'px-2 lg:px-3 sidebar-collapsed' : 'px-2 lg:px-3'"
    @mouseover="
        if (!collapsed) return;
        const el = $event.target.closest('.menu-item, .menu-subitem');
        if (!el) { tooltip.show = false; return; }
        const text = el.getAttribute('title') || el.getAttribute('aria-label') || el.querySelector('.menu-item-label')?.textContent.trim() || '';
        if (!text) return;
        const rect = el.getBoundingClientRect();
        tooltip.text = text;
        tooltip.x = rect.right + 8;
        tooltip.y = rect.top + rect.height / 2;
        tooltip.show = true;
    "
    @mouseleave="tooltip.show = false"
    x-data="{
        tooltip: { text: '', x: 0, y: 0, show: false },
        // Label of the open submenu ('' = main menu). It starts on the submenu of the current page
        // ([data-active-submenu], read in init). Back opens the main menu.
        submenu: '',
        // Depth of the open resource menu (-1 = main menu or submenu). It starts on the
        // deepest menu of the current page; Back opens the menu one depth lower.
        navLevel: -1,
        // Direction of the last menu swap ('forward' or 'back'); it selects the enter animation of every menu.
        navSwap: '',
        // macOS/iOS use ⌘; Windows/Linux use Ctrl+
        modKeyLabel: (() => {
            const platform = navigator.userAgentData?.platform || navigator.platform || '';
            const ua = navigator.userAgent || '';
            return /Mac|iPhone|iPad|iPod/i.test(platform) || /Mac OS X|Macintosh/i.test(ua) ? '⌘' : 'Ctrl+';
        })(),
        // Shows the menu of the current page: its deepest resource menu, or the submenu that holds it.
        showCurrentPageMenu() {
            this.submenu = this.$root.querySelector('[data-active-submenu]')?.dataset.activeSubmenu || '';
            this.navLevel = Number([...this.$root.querySelectorAll('[data-nav-level]')].pop()?.dataset.navLevel ?? -1);
        },
        init() {
                this.showCurrentPageMenu();
                if (@js($desktop)) {
                    // The open resource menu depth is kept across page loads: a page that opens a deeper menu
                    // (e.g. a backup) plays the forward swap, and a page that opens a lower one plays the back swap.
                    const previousLevel = sessionStorage.getItem('navLevel');
                    if (previousLevel !== null && window.matchMedia('(min-width: 64rem)').matches) {
                        const levelChange = this.navLevel - Number(previousLevel);
                        this.navSwap = levelChange > 0 ? 'forward' : (levelChange < 0 ? 'back' : '');
                    }
                    sessionStorage.setItem('navLevel', this.navLevel);
                    this.$watch('navLevel', level => sessionStorage.setItem('navLevel', level));
                } else {
                    // The resource menus teleport into the desktop sidebar. The mobile drawer moves them into
                    // its own slots when it opens, and gives them back when the desktop sidebar shows.
                    const moveResourceMenus = toDrawer => this.$root.querySelectorAll('[data-mobile-nav-context]').forEach(drawerSlot => {
                        const desktopSlot = document.getElementById('main-nav-context-' + drawerSlot.dataset.mobileNavContext);
                        if (desktopSlot) {
                            toDrawer ? drawerSlot.append(...desktopSlot.childNodes) : desktopSlot.append(...drawerSlot.childNodes);
                        }
                    });
                    this.desktopQuery = window.matchMedia('(min-width: 64rem)');
                    this.onDesktopChange = event => event.matches && moveResourceMenus(false);
                    this.desktopQuery.addEventListener('change', this.onDesktopChange);
                    // A closed drawer forgets where Back went: it opens again on the menu of the current page.
                    this.$watch('open', isOpen => {
                        if (isOpen) {
                            moveResourceMenus(true);
                        } else {
                            this.navSwap = '';
                            this.showCurrentPageMenu();
                        }
                    });
                }
                window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
                    if (localStorage.getItem('theme') === 'system') { window.applyStoredTheme(); }
                });
                window.applyStoredTheme();
            },
        destroy() {
            this.desktopQuery?.removeEventListener('change', this.onDesktopChange);
        },
    }">
    {{-- Search is only useful when workspace resources are available --}}
    @if (isSubscribed() || ! isCloud())
        <div class="px-1 pb-3" :class="collapsed && 'lg:px-0'">
            {{-- On pages with settings or sections the palette also searches the current page --}}
            <button @click="$dispatch('open-global-search')" type="button"
                x-data="{ hasPageSearch: false }"
                x-init="$nextTick(() => hasPageSearch = (window.currentPageSearchItems?.() ?? []).length > 0)"
                :title="hasPageSearch
                    ? 'Search this page and everything (Press / or ' + modKeyLabel + 'K, then Tab to switch)'
                    : 'Search (Press / or ' + modKeyLabel + 'K)'"
                class="menu-item menu-search justify-between !rounded-lg !bg-neutral-100 dark:!bg-white/[0.04] hover:!bg-neutral-200 dark:hover:!bg-white/[0.07] !text-fg-faint">
                <span class="flex items-center gap-2.5 min-w-0">
                    <x-reicon name="search" class="menu-item-icon" />
                    <span class="menu-item-label truncate" :class="collapsed && 'lg:hidden'">Search</span>
                </span>
                <kbd class="px-1.5 py-0.5 text-[11px] font-medium text-fg-faint bg-neutral-200 dark:bg-white/[0.06] rounded-lg border border-transparent dark:border-white/5"
                    :class="collapsed && 'lg:hidden'" x-text="modKeyLabel + 'K'"></kbd>
            </button>
        </div>
    @endif

    <ul role="list" class="sidebar-menu -mx-1 flex min-h-0 flex-1 flex-col gap-y-0.5 overflow-y-auto px-1 pb-2">
        @if (isSubscribed() || !isCloud())
            @php
                // An entry with children opens a submenu in place of the main menu (back button + its pages).
                $navSections = collect([
                    'Workspace' => [
                        ['label' => 'Dashboard', 'icon' => 'dashboard', 'href' => '/', 'active' => request()->is('/', 'dashboard/*', 'canvas')],
                        ['label' => 'Projects', 'icon' => 'projects', 'href' => route('project.index'), 'active' => request()->is('projects', 'projects/*', 'project/*')],
                        ['label' => 'Monitoring', 'icon' => 'graph', 'children' => [
                            ['label' => 'Analytics', 'icon' => 'analytics', 'href' => route('analytics'), 'active' => request()->is('analytics')],
                            ['label' => 'Metrics', 'icon' => 'graph', 'href' => route('metrics'), 'active' => request()->is('metrics')],
                        ]],
                        ['label' => 'Terminal', 'icon' => 'browser-terminal', 'href' => route('terminal'), 'active' => request()->is('terminal*'), 'navigate' => false, 'visible' => auth()->user()?->can('canAccessTerminal')],
                    ],
                    'Infrastructure' => [
                        ['label' => 'Servers', 'icon' => 'servers', 'href' => '/servers', 'active' => request()->is('server/*', 'servers', 'servers/*')],
                        ['label' => 'Integrations', 'icon' => 'link', 'children' => [
                            ['label' => 'Sources', 'icon' => 'sources', 'href' => route('source.all'), 'active' => request()->is('sources', 'source/*')],
                            ['label' => 'Destinations', 'icon' => 'destinations', 'href' => route('destination.index'), 'active' => request()->is('destinations', 'destination/*')],
                            ['label' => 'Registries', 'icon' => 'layers', 'href' => route('registries.index'), 'active' => request()->is('registries'), 'visible' => auth()->user()?->isAdmin()],
                            ['label' => 'S3 Storage', 'icon' => 'storages', 'href' => route('storage.index'), 'active' => request()->is('storages', 'storages/*')],
                        ]],
                        ['label' => 'Shared Variables', 'icon' => 'variables', 'children' => [
                            ['label' => 'Overview', 'icon' => 'dashboard', 'href' => route('shared-variables.index'), 'active' => request()->is('shared-variables')],
                            ['label' => 'Team', 'icon' => 'teams', 'href' => route('shared-variables.team.index'), 'active' => request()->is('shared-variables/team*')],
                            ['label' => 'Projects', 'icon' => 'projects', 'href' => route('shared-variables.project.index'), 'active' => request()->is('shared-variables/projects', 'shared-variables/project/*')],
                            ['label' => 'Environments', 'icon' => 'layers', 'href' => route('shared-variables.environment.index'), 'active' => request()->is('shared-variables/environments*')],
                            ['label' => 'Servers', 'icon' => 'servers', 'href' => route('shared-variables.server.index'), 'active' => request()->is('shared-variables/servers', 'shared-variables/server/*')],
                        ]],
                    ],
                    'Manage' => [
                        ['label' => 'Team', 'icon' => 'teams', 'children' => [
                            ['label' => 'General', 'icon' => 'settings', 'href' => route('team.index'), 'active' => request()->is('team')],
                            ['label' => 'Members', 'icon' => 'teams', 'href' => route('team.member.index'), 'active' => request()->is('team/members*')],
                            ['label' => 'Audit log', 'icon' => 'time-back', 'href' => route('team.audit-log'), 'active' => request()->is('team/audit-log'), 'visible' => auth()->user()?->isAdminOfTeam(currentTeam()->id)],
                            ['label' => 'Admin View', 'icon' => 'admin', 'href' => route('team.admin-view'), 'active' => request()->is('team/admin'), 'visible' => isInstanceAdmin()],
                            ['label' => 'Danger Zone', 'icon' => 'danger', 'href' => route('team.danger-zone'), 'active' => request()->is('team/danger'), 'visible' => auth()->user()?->can('delete', currentTeam()), 'sectionStart' => true],
                        ]],
                        ['label' => 'Notifications', 'icon' => 'notifications', 'children' => [
                            ['label' => 'Email', 'icon' => 'mail', 'href' => route('notifications.email'), 'active' => request()->is('notifications/email')],
                            ['label' => 'Discord', 'brandIcon' => 'discord', 'href' => route('notifications.discord'), 'active' => request()->is('notifications/discord')],
                            ['label' => 'Telegram', 'brandIcon' => 'telegram', 'href' => route('notifications.telegram'), 'active' => request()->is('notifications/telegram')],
                            ['label' => 'Slack', 'brandIcon' => 'slack', 'href' => route('notifications.slack'), 'active' => request()->is('notifications/slack')],
                            ['label' => 'Pushover', 'brandIcon' => 'pushover', 'href' => route('notifications.pushover'), 'active' => request()->is('notifications/pushover')],
                            ['label' => 'Webhook', 'icon' => 'destinations', 'href' => route('notifications.webhook'), 'active' => request()->is('notifications/webhook')],
                        ]],
                        ['label' => 'Keys & Tokens', 'icon' => 'keys', 'children' => [
                            ['label' => 'Private Keys', 'icon' => 'keys', 'href' => route('security.private-key.index'), 'active' => request()->is('security/private-key*')],
                            ['label' => 'Cloud Tokens', 'icon' => 'cloud', 'href' => route('security.cloud-tokens'), 'active' => request()->is('security/cloud-tokens*'), 'visible' => auth()->user()?->can('viewAny', App\Models\CloudProviderToken::class)],
                            ['label' => 'Integration Tokens', 'icon' => 'network', 'href' => route('security.integration-tokens'), 'active' => request()->is('security/integration-tokens'), 'visible' => auth()->user()?->can('viewAny', App\Models\IntegrationToken::class)],
                            ['label' => 'Cloud-Init Scripts', 'icon' => 'file-content', 'href' => route('security.cloud-init-scripts'), 'active' => request()->is('security/cloud-init-scripts*'), 'visible' => auth()->user()?->can('viewAny', App\Models\CloudInitScript::class)],
                            ['label' => 'API Tokens', 'icon' => 'code', 'href' => route('security.api-tokens'), 'active' => request()->is('security/api-tokens')],
                        ]],
                        ['label' => 'Subscription', 'icon' => 'subscription', 'href' => isCloud() ? route('subscription.show') : '', 'active' => request()->is('subscription*'), 'visible' => isCloud() && auth()->user()->isAdmin()],
                        ['label' => 'Tags', 'icon' => 'tags', 'href' => route('tags.show'), 'active' => request()->is('tags*', 'canvas/tags')],
                        ['label' => 'Settings', 'icon' => 'settings', 'visible' => isInstanceAdmin(), 'children' => [
                            ['label' => 'General', 'icon' => 'settings', 'href' => route('settings.index'), 'active' => request()->is('settings')],
                            ['label' => 'Advanced', 'icon' => 'grid', 'href' => route('settings.advanced'), 'active' => request()->is('settings/advanced')],
                            ['label' => 'Updates', 'icon' => 'refresh3', 'href' => route('settings.updates'), 'active' => request()->is('settings/updates')],
                            ['label' => 'Authentication', 'icon' => 'keys', 'href' => route('settings.oauth'), 'active' => request()->is('settings/oauth*'), 'sectionStart' => true],
                            ['label' => 'Email', 'icon' => 'mail', 'href' => route('settings.email'), 'active' => request()->is('settings/email')],
                            ['label' => 'Backup', 'icon' => 'database', 'href' => route('settings.backup'), 'active' => request()->is('settings/backup')],
                        ]],
                    ],
                ])->map(fn (array $items) => collect($items)
                    ->filter(fn (array $item): bool => (bool) ($item['visible'] ?? true))
                    ->map(function (array $item): array {
                        if (! isset($item['children'])) {
                            return $item;
                        }
                        $children = array_values(array_filter($item['children'], fn (array $child): bool => (bool) ($child['visible'] ?? true)));

                        return [...$item, 'children' => $children, 'href' => $children[0]['href'], 'active' => collect($children)->contains('active', true)];
                    })
                    ->all());
                $activeSubmenu = $navSections->flatten(1)->first(fn (array $item): bool => isset($item['children']) && $item['active']);
                // Resource menus by depth, registered by registerMainNavContext() (e.g. from
                // components/main-nav-menu.blade.php). Each one teleports itself into #main-nav-context-{depth}
                // (the desktop sidebar); the mobile drawer moves it into its own slot while it shows (see init).
                $navContext = request()->attributes->get('mainNavContext', []);
            @endphp
            {{-- On a resource page the deepest resource menu replaces the main menu. Back shows the
                 menu one depth lower, and then the main menu (or its submenu), without leaving the page. --}}
            @foreach ($navContext as $depth => $navMenu)
                @php
                    $previousDepth = collect($navContext)->keys()->filter(fn (int $key): bool => $key < $depth)->last() ?? -1;
                @endphp
                <li x-show="navLevel === {{ $depth }}" data-nav-level="{{ $depth }}" @unless ($loop->last) x-cloak @endunless
                    class="contents">
                    <ul role="list" class="flex flex-col gap-y-0.5" :class="navSwap && 'nav-swap-' + navSwap">
                        <li class="mb-1">
                            <button type="button" @click="navLevel = {{ $previousDepth }}; navSwap = 'back'"
                                title="{{ $navMenu['backTitle'] }}" aria-label="{{ $navMenu['backTitle'] }}"
                                class="menu-item menu-back bg-transparent! hover:bg-neutral-100! dark:hover:bg-white/[0.06]!" :class="collapsed && 'lg:justify-center lg:px-0'">
                                <span class="menu-back-icon flex shrink-0 items-center justify-center">
                                    <x-reicon name="chevron-down" class="size-3 rotate-90 text-coollabs dark:text-fg-dim" />
                                </span>
                                <span class="flex-1 truncate pr-6 text-center font-medium text-black dark:text-fg"
                                    :class="collapsed && 'lg:hidden'">{{ $navMenu['label'] }}</span>
                            </button>
                        </li>
                        <li @if ($desktop) id="main-nav-context-{{ $depth }}" @else data-mobile-nav-context="{{ $depth }}" @endif
                            class="nav-context min-w-0"></li>
                    </ul>
                </li>
            @endforeach
            {{-- Submenus: a parent entry opens its submenu in place, and the user picks a page (the mobile
                 drawer stays open until then). Back shows the main menu without leaving the page. --}}
            @php
                $renderedSubmenus = $navSections->flatten(1)->filter(fn (array $item): bool => isset($item['children']));
            @endphp
            @foreach ($renderedSubmenus as $submenuItem)
                <li x-show="navLevel === -1 && submenu === @js($submenuItem['label'])"
                    @if ($submenuItem['active']) data-active-submenu="{{ $submenuItem['label'] }}" @endif
                    @if (! $submenuItem['active'] || $navContext) x-cloak @endif class="contents">
                    <ul role="list" class="flex flex-col gap-y-0.5" :class="navSwap && 'nav-swap-' + navSwap">
                        <li class="mb-1">
                            <button type="button" @click="submenu = ''; navSwap = 'back'" title="Back to the main menu" aria-label="Back to the main menu"
                                class="menu-item menu-back bg-transparent! hover:bg-neutral-100! dark:hover:bg-white/[0.06]!" :class="collapsed && 'lg:justify-center lg:px-0'">
                                {{-- As wide as a menu icon (with its tile), so the chevron lines up with the icons below. --}}
                                <span class="menu-back-icon flex shrink-0 items-center justify-center">
                                    <x-reicon name="chevron-down" class="size-3 rotate-90 text-coollabs dark:text-fg-dim" />
                                </span>
                                <span class="flex-1 truncate pr-6 text-center font-medium text-black dark:text-fg"
                                    :class="collapsed && 'lg:hidden'">{{ $submenuItem['label'] }}</span>
                            </button>
                        </li>
                        @foreach ($submenuItem['children'] as $child)
                            @if ($child['sectionStart'] ?? false)
                                <li class="my-2 border-t border-neutral-200 dark:border-white/[0.06]" aria-hidden="true"></li>
                            @endif
                            <li>
                                <a title="{{ $child['label'] }}" href="{{ $child['href'] }}" {{ wireNavigate() }}
                                    @if ($child['active']) aria-current="page" @endif
                                    @class(['menu-item', 'menu-item-active' => $child['active']])
                                    :class="collapsed && 'lg:justify-center lg:px-0'">
                                    @isset($child['brandIcon'])
                                        {{-- The mask is on an inner span, so the outer span keeps the icon tile. --}}
                                        <span class="menu-item-icon">
                                            <span class="block size-full bg-current"
                                                style="mask: url('{{ asset('svgs/'.$child['brandIcon'].'.svg') }}') center / contain no-repeat; -webkit-mask: url('{{ asset('svgs/'.$child['brandIcon'].'.svg') }}') center / contain no-repeat;"></span>
                                        </span>
                                    @else
                                        <x-reicon :name="$child['icon']" class="menu-item-icon" />
                                    @endisset
                                    <span class="menu-item-label" :class="collapsed && 'lg:hidden'">{{ $child['label'] }}</span>
                                </a>
                            </li>
                            @if ($child['active'] && $desktop)
                                {{-- A page can teleport its own sub-items here (e.g. the OAuth providers). --}}
                                <li id="main-nav-active-children" class="empty:hidden ml-5 border-l border-neutral-200 pl-2 dark:border-white/[0.08]" :class="collapsed && 'lg:hidden'"></li>
                            @endif
                        @endforeach
                    </ul>
                </li>
            @endforeach
            <li x-show="navLevel === -1 && !submenu" @if ($activeSubmenu || $navContext) x-cloak @endif class="contents">
                <ul role="list" class="flex flex-col gap-y-0.5" :class="navSwap && 'nav-swap-' + navSwap">
            @foreach ($navSections as $sectionLabel => $sectionItems)
                {{-- The first section needs no title. --}}
                @unless ($loop->first)
                    <li class="nav-section mt-3" aria-hidden="true"
                        :class="collapsed && 'lg:mx-2.5 lg:my-2 lg:h-0 lg:overflow-hidden lg:border-t lg:border-neutral-200 lg:p-0 lg:text-transparent dark:lg:border-white/10'">
                        {{ $sectionLabel }}</li>
                @endunless
                @foreach ($sectionItems as $item)
                    <li>
                        {{-- A parent entry is a button, not a link: wire:navigate would start a page load on mouse down. --}}
                        @isset($item['children'])
                            <button type="button" title="{{ $item['label'] }}" @click="submenu = @js($item['label']); navSwap = 'forward'"
                                @class(['menu-item w-full', 'menu-item-active' => $item['active']])
                                :class="collapsed && 'lg:justify-center lg:px-0'">
                                <x-reicon :name="$item['icon']" class="menu-item-icon" />
                                <span class="menu-item-label flex-1 text-left" :class="collapsed && 'lg:hidden'">{{ $item['label'] }}</span>
                                <x-reicon name="chevron-down" class="nav-chevron size-3 -rotate-90 text-coollabs dark:text-fg-faint" ::class="collapsed && 'lg:hidden'" />
                            </button>
                        @else
                            <a title="{{ $item['label'] }}" href="{{ $item['href'] }}"
                                @if ($item['navigate'] ?? true) {{ wireNavigate() }} @endif
                                @class(['menu-item', 'menu-item-active' => $item['active']])
                                :class="collapsed && 'lg:justify-center lg:px-0'">
                                <x-reicon :name="$item['icon']" class="menu-item-icon" />
                                <span class="menu-item-label flex-1" :class="collapsed && 'lg:hidden'">{{ $item['label'] }}</span>
                            </a>
                        @endisset
                    </li>
                @endforeach
            @endforeach
                </ul>
            </li>
            <li class="flex-1" aria-hidden="true"></li>
        @endif
        @if (auth()->id() === 0 && (isCloud() || isDev()))
            {{-- Part of the main menu: hidden while a submenu or a resource menu shows. --}}
            <li x-show="navLevel === -1 && !submenu" @if (($activeSubmenu ?? null) || ($navContext ?? [])) x-cloak @endif>
                <a title="Admin" {{ wireNavigate() }}
                    class="nav-admin {{ request()->is('admin') ? 'menu-item-active menu-item' : 'menu-item' }}"
                    :class="collapsed && 'lg:justify-center lg:px-0'" href="{{ route('admin.index') }}">
                    <x-reicon name="fire" class="menu-item-icon" />
                    <span class="menu-item-label" :class="collapsed && 'lg:hidden'">Admin</span>
                </a>
            </li>
        @endif
        @if (isCloud() && ! isSubscribed())
            {{-- Unsubscribed cloud has no workspace items — keep these at the top of the list. --}}
            <li class="nav-section" aria-hidden="true"
                :class="collapsed && 'lg:mx-2.5 lg:my-2 lg:h-0 lg:overflow-hidden lg:border-t lg:border-neutral-200 lg:p-0 lg:text-transparent dark:lg:border-white/10'">
                Account</li>
            <li>
                <a title="Subscription" {{ wireNavigate() }}
                    class="{{ request()->is('subscription*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                    :class="collapsed && 'lg:justify-center lg:px-0'"
                    href="{{ isSubscriptionOnGracePeriod() ? route('subscription.show') : route('subscription.index') }}">
                    <x-reicon name="subscription" class="menu-item-icon" />
                    <span class="menu-item-label" :class="collapsed && 'lg:hidden'">Subscription</span>
                </a>
            </li>
            @if (auth()->user()->teams()->get()->count() > 1)
                <li class="mt-2">
                    <livewire:navbar-delete-team />
                </li>
            @endif
        @endif
    </ul>
    {{-- Sidebar footer (desktop only; mobile uses a temporary slide-over). It stays visible;
         only the menu above it scrolls on short or zoomed-in screens. --}}
    <div class="sidebar-footer shrink-0 -mx-2 hidden flex-col gap-1 bg-neutral-50 px-2 py-2 dark:bg-transparent lg:-mx-3 lg:flex lg:px-3">
        <div class="flex items-center gap-1" :class="collapsed ? 'flex-col justify-center' : 'justify-between'">
            <x-top-user-menu sidebar />
            <button type="button" @click="toggleSidebar()" title="Toggle sidebar" aria-label="Toggle sidebar"
                class="menu-item sidebar-toggle w-8 shrink-0 justify-center px-0">
                <svg class="menu-item-icon" viewBox="0 0 24 24" fill="none">
                    <rect x="3" y="5" width="18" height="14" rx="4" stroke="currentColor" stroke-width="1.6" />
                    <path d="M16 9v6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                </svg>
            </button>
        </div>
    </div>
    <div x-show="collapsed && tooltip.show" x-cloak x-transition.opacity.duration.100ms
        :style="`left: ${tooltip.x}px; top: ${tooltip.y}px;`"
        class="fixed z-[10000] -translate-y-1/2 px-2 py-1 text-xs font-medium rounded-lg bg-neutral-900 dark:bg-raised text-white whitespace-nowrap pointer-events-none shadow-dropdown border border-neutral-700 dark:border-white/10"
        x-text="tooltip.text"></div>
</nav>
