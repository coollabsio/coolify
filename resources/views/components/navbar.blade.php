<nav class="flex flex-col flex-1 bg-white border-r border-neutral-200 dark:border-white/[0.06] dark:bg-panel pt-2"
    :class="collapsed ? 'px-2 lg:px-3 sidebar-collapsed' : 'px-2 lg:px-3'"
    @mouseover="
        if (!collapsed) return;
        const el = $event.target.closest('.menu-item, .menu-subitem');
        if (!el) { tooltip.show = false; return; }
        const text = el.getAttribute('title') || el.getAttribute('aria-label') || '';
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
        init() {
                window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
                    const userSettings = localStorage.getItem('theme');
                    if (userSettings !== 'system') { return; }
                    document.documentElement.classList.toggle('dark', e.matches);
                });
                this.queryTheme();
            },
            queryTheme() {
                const darkModePreference = window.matchMedia('(prefers-color-scheme: dark)').matches;
                const userSettings = localStorage.getItem('theme') || 'dark';
                localStorage.setItem('theme', userSettings);
                let isDark = false;
                if (userSettings === 'dark') {
                    document.documentElement.classList.add('dark');
                    isDark = true;
                } else if (userSettings === 'light') {
                    document.documentElement.classList.remove('dark');
                } else if (darkModePreference) {
                    document.documentElement.classList.add('dark');
                    isDark = true;
                } else {
                    document.documentElement.classList.remove('dark');
                }
                document.querySelector('meta[name=theme-color]')?.setAttribute('content', isDark ? '#0d0d0d' : '#ffffff');
            }
    }">
    {{-- Search --}}
    <div class="px-1 pb-3" :class="collapsed && 'lg:px-0 lg:flex lg:justify-center'">
        <button @click="$dispatch('open-global-search')" type="button" title="Search (Press / or ⌘K)"
            class="menu-item justify-between !bg-neutral-100 dark:!bg-white/[0.04] hover:!bg-neutral-200 dark:hover:!bg-white/[0.07] !text-fg-faint"
            :class="collapsed && 'lg:w-8 lg:justify-center lg:px-0'">
            <span class="flex items-center gap-2.5 min-w-0">
                <x-reicon name="search" class="menu-item-icon" />
                <span class="menu-item-label" :class="collapsed && 'lg:hidden'">Search</span>
            </span>
            <kbd class="px-1.5 py-0.5 text-[11px] font-medium text-fg-faint bg-neutral-200 dark:bg-white/[0.06] rounded-md border border-transparent dark:border-white/5"
                :class="collapsed && 'lg:hidden'">⌘K</kbd>
        </button>
    </div>

    <ul role="list" class="flex min-h-0 flex-1 flex-col gap-y-0.5 overflow-y-auto pb-2 scrollbar">
        @if (isSubscribed() || !isCloud())
            {{-- Workspace --}}
            <li class="nav-section" :class="collapsed && 'lg:hidden'">Workspace</li>
            <li>
                <a title="Dashboard" href="/" {{ wireNavigate() }}
                    class="{{ request()->is('/') ? 'menu-item-active menu-item' : 'menu-item' }}"
                    :class="collapsed && 'lg:justify-center lg:px-0'">
                    <x-reicon name="dashboard" class="menu-item-icon" />
                    <span class="menu-item-label" :class="collapsed && 'lg:hidden'">Dashboard</span>
                </a>
            </li>
            <li>
                <a title="Projects" {{ wireNavigate() }}
                    class="{{ request()->is('project/*') || request()->is('projects') ? 'menu-item menu-item-active' : 'menu-item' }}"
                    :class="collapsed && 'lg:justify-center lg:px-0'" href="/projects">
                    <x-reicon name="projects" class="menu-item-icon" />
                    <span class="menu-item-label" :class="collapsed && 'lg:hidden'">Projects</span>
                </a>
            </li>
            @can('canAccessTerminal')
                <li>
                    <a title="Terminal" {{ wireNavigate() }}
                        class="{{ request()->is('terminal*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                        :class="collapsed && 'lg:justify-center lg:px-0'" href="{{ route('terminal') }}">
                        <x-reicon name="browser-terminal" class="menu-item-icon" />
                        <span class="menu-item-label" :class="collapsed && 'lg:hidden'">Terminal</span>
                    </a>
                </li>
            @endcan
            {{-- Infrastructure --}}
            <li class="nav-section mt-3" :class="collapsed && 'lg:hidden'">Infrastructure</li>
            <li>
                <a title="Servers" {{ wireNavigate() }}
                    class="{{ request()->is('server/*') || request()->is('servers') ? 'menu-item menu-item-active' : 'menu-item' }}"
                    :class="collapsed && 'lg:justify-center lg:px-0'" href="/servers">
                    <x-reicon name="servers" class="menu-item-icon" />
                    <span class="menu-item-label" :class="collapsed && 'lg:hidden'">Servers</span>
                </a>
            </li>
            <li>
                <a title="Sources" {{ wireNavigate() }}
                    class="{{ request()->is('source*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                    :class="collapsed && 'lg:justify-center lg:px-0'" href="{{ route('source.all') }}">
                    <x-reicon name="sources" class="menu-item-icon" />
                    <span class="menu-item-label" :class="collapsed && 'lg:hidden'">Sources</span>
                </a>
            </li>
            <li>
                <a title="Destinations" {{ wireNavigate() }}
                    class="{{ request()->is('destination*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                    :class="collapsed && 'lg:justify-center lg:px-0'" href="{{ route('destination.index') }}">
                    <x-reicon name="destinations" class="menu-item-icon" />
                    <span class="menu-item-label" :class="collapsed && 'lg:hidden'">Destinations</span>
                </a>
            </li>
            <li>
                <a title="S3 Storage" {{ wireNavigate() }}
                    class="{{ request()->is('storages*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                    :class="collapsed && 'lg:justify-center lg:px-0'" href="{{ route('storage.index') }}">
                    <x-reicon name="storages" class="menu-item-icon" />
                    <span class="menu-item-label" :class="collapsed && 'lg:hidden'">S3 Storage</span>
                </a>
            </li>
            <li>
                <a title="Shared variables" {{ wireNavigate() }}
                    class="{{ request()->is('shared-variables*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                    :class="collapsed && 'lg:justify-center lg:px-0'" href="{{ route('shared-variables.index') }}">
                    <x-reicon name="variables" class="menu-item-icon" />
                    <span class="menu-item-label" :class="collapsed && 'lg:hidden'">Shared Variables</span>
                </a>
            </li>

            {{-- Manage --}}
            <li class="nav-section mt-3" :class="collapsed && 'lg:hidden'">Manage</li>
            <li>
                <a title="Team" {{ wireNavigate() }}
                    class="{{ request()->is('team*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                    :class="collapsed && 'lg:justify-center lg:px-0'" href="{{ route('team.index') }}">
                    <x-reicon name="teams" class="menu-item-icon" />
                    <span class="menu-item-label" :class="collapsed && 'lg:hidden'">Team</span>
                </a>
            </li>
            <li>
                <a title="Notifications" {{ wireNavigate() }}
                    class="{{ request()->is('notifications*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                    :class="collapsed && 'lg:justify-center lg:px-0'" href="{{ route('notifications.email') }}">
                    <x-reicon name="notifications" class="menu-item-icon" />
                    <span class="menu-item-label" :class="collapsed && 'lg:hidden'">Notifications</span>
                </a>
            </li>

            <li>
                <a title="Keys & Tokens" {{ wireNavigate() }}
                    class="{{ request()->is('security*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                    :class="collapsed && 'lg:justify-center lg:px-0'" href="{{ route('security.private-key.index') }}">
                    <x-reicon name="keys" class="menu-item-icon" />
                    <span class="menu-item-label" :class="collapsed && 'lg:hidden'">Keys & Tokens</span>
                </a>
            </li>
            <li>
                <a title="Tags" {{ wireNavigate() }}
                    class="{{ request()->is('tags*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                    :class="collapsed && 'lg:justify-center lg:px-0'" href="{{ route('tags.show') }}">
                    <x-reicon name="tags" class="menu-item-icon" />
                    <span class="menu-item-label" :class="collapsed && 'lg:hidden'">Tags</span>
                </a>
            </li>
            @if (isInstanceAdmin())
                <li>
                    <a title="Settings" {{ wireNavigate() }}
                        class="{{ request()->is('settings*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                        :class="collapsed && 'lg:justify-center lg:px-0'" href="{{ route('settings.index') }}">
                        <x-reicon name="settings" class="menu-item-icon" />
                        <span class="menu-item-label" :class="collapsed && 'lg:hidden'">Settings</span>
                    </a>
                </li>
            @endif

            <li class="flex-1" aria-hidden="true"></li>
        @endif
        @if (!isSubscribed() && isCloud() && auth()->user()->teams()->get()->count() > 1)
            <livewire:navbar-delete-team />
        @endif
    </ul>
    {{-- Sticky sidebar collapser --}}
    <div class="sticky bottom-0 mt-auto -mx-2 lg:-mx-3 border-t border-neutral-200 dark:border-white/[0.06] bg-white dark:bg-panel px-2 lg:px-3 py-2">
        <button type="button" @click="toggleSidebar()" title="Toggle sidebar" aria-label="Toggle sidebar"
            class="menu-item mx-auto w-8 justify-center px-0">
            <svg class="menu-item-icon" viewBox="0 0 24 24" fill="none">
                <rect x="3" y="4" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.6" />
                <path d="M9 4v16" stroke="currentColor" stroke-width="1.6" />
            </svg>
        </button>
    </div>
    <div x-show="collapsed && tooltip.show" x-cloak x-transition.opacity.duration.100ms
        :style="`left: ${tooltip.x}px; top: ${tooltip.y}px;`"
        class="fixed z-[100] -translate-y-1/2 px-2 py-1 text-xs font-medium rounded-lg bg-neutral-900 dark:bg-raised text-white whitespace-nowrap pointer-events-none shadow-lg border border-neutral-700 dark:border-white/10"
        x-text="tooltip.text"></div>
</nav>
