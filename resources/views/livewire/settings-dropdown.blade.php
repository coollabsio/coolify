<div x-data="{
    dropdownOpen: false,
    search: '',
    allEntries: [],
    page: 1,
    perPage: 1,
    darkColorContent: getComputedStyle($el).getPropertyValue('--color-base'),
    whiteColorContent: getComputedStyle($el).getPropertyValue('--color-white'),
    init() {
        this.mounted();
        // Load all entries when component initializes
        this.allEntries = @js($entries->toArray());
    },
    markEntryAsRead(tagName) {
        // Update the entry in our local Alpine data
        const entry = this.allEntries.find(e => e.tag_name === tagName);
        if (entry) {
            entry.is_read = true;
        }
        // Call Livewire to update server-side
        $wire.markAsRead(tagName);
    },
    markAllEntriesAsRead() {
        // Update all entries in our local Alpine data
        this.allEntries.forEach(entry => {
            entry.is_read = true;
        });
        // Call Livewire to update server-side
        $wire.markAllAsRead();
    },
    switchWidth() {
        if (this.full === 'full') {
            localStorage.setItem('pageWidth', 'center');
        } else {
            localStorage.setItem('pageWidth', 'full');
        }
        window.location.reload();
    },
    setZoom(zoom) {
        localStorage.setItem('zoom', zoom);
        window.location.reload();
    },
    setTheme(type) {
        this.theme = type;
        localStorage.setItem('theme', type);
        this.queryTheme();
    },
    queryTheme() {
        const darkModePreference = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const userSettings = localStorage.getItem('theme') || 'dark';
        localStorage.setItem('theme', userSettings);

        const themeMetaTag = document.querySelector('meta[name=theme-color]');
        let isDark = false;

        if (userSettings === 'dark') {
            document.documentElement.classList.add('dark');
            this.theme = 'dark';
            isDark = true;
        } else if (userSettings === 'light') {
            document.documentElement.classList.remove('dark');
            this.theme = 'light';
            isDark = false;
        } else if (userSettings === 'system') {
            this.theme = 'system';
            if (darkModePreference) {
                document.documentElement.classList.add('dark');
                isDark = true;
            } else {
                document.documentElement.classList.remove('dark');
                isDark = false;
            }
        }

        // Update theme-color meta tag
        if (themeMetaTag) {
            themeMetaTag.setAttribute('content', isDark ? '#101010' : '#ffffff');
        }
    },
    mounted() {
        this.full = localStorage.getItem('pageWidth');
        this.zoom = localStorage.getItem('zoom');
        this.queryTheme();
    },
    get filteredEntries() {
        let entries = [...this.allEntries];

        // Apply search filter if search term exists
        if (this.search && this.search.trim() !== '') {
            const searchLower = this.search.trim().toLowerCase();
            entries = entries.filter(entry => {
                return (entry.title?.toLowerCase().includes(searchLower) ||
                    entry.content?.toLowerCase().includes(searchLower) ||
                    entry.tag_name?.toLowerCase().includes(searchLower));
            });
        }

        // Always sort: unread first, then by published date (newest first)
        return entries.sort((a, b) => {
            // First sort by read status (unread first)
            if (a.is_read !== b.is_read) {
                return a.is_read ? 1 : -1; // unread (false) comes before read (true)
            }
            // Then sort by published date (newest first)
            return new Date(b.published_at) - new Date(a.published_at);
        });
    },
    get paginatedEntries() {
        const start = (this.page - 1) * this.perPage;

        return this.filteredEntries.slice(start, start + this.perPage);
    },
    get lastPage() {
        return Math.max(1, Math.ceil(this.filteredEntries.length / this.perPage));
    },
    get firstVisibleEntry() {
        return this.filteredEntries.length === 0 ? 0 : ((this.page - 1) * this.perPage) + 1;
    },
    get lastVisibleEntry() {
        return Math.min(this.page * this.perPage, this.filteredEntries.length);
    },
    goToPage(page) {
        this.page = Math.min(Math.max(page, 1), this.lastPage);
    }
}" @click.outside="dropdownOpen = false"
    class="{{ in_array($trigger, ['changelog-sidebar', 'account-menu'], true) ? 'w-full' : '' }}">
    @if ($trigger === 'changelog-sidebar')
        <button wire:click="openWhatsNewModal" type="button" title="What's New" aria-label="What's New"
            class="relative text-left menu-item">
            <svg class="menu-item-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                    d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.091-3.091L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.091-3.091L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.091 3.091L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.091 3.091ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.456-2.456L14.25 6l1.035-.259a3.375 3.375 0 0 0 2.456-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z" />
            </svg>
            <span class="text-left menu-item-label" :class="collapsed && 'lg:hidden'">What's New</span>
            @if ($unreadCount > 0)
                <span
                    class="absolute right-2 top-1/2 -translate-y-1/2 bg-error text-white text-[10px] rounded-full min-w-4 h-4 px-1 flex items-center justify-center"
                    aria-label="{{ $unreadCount }} unread changelog {{ Str::plural('entry', $unreadCount) }}">
                    {{ $unreadCount > 9 ? '9+' : $unreadCount }}
                </span>
            @endif
        </button>
    @elseif ($trigger === 'account-menu')
        <button wire:click="openWhatsNewModal" @click="open = false" type="button"
            class="listbox-option relative w-full text-left">
            <span class="flex items-center gap-2">
                <svg class="size-4 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                    aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                        d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.091-3.091L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.091-3.091L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.091 3.091L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.091 3.091ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.456-2.456L14.25 6l1.035-.259a3.375 3.375 0 0 0 2.456-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" />
                </svg>
                What's New
            </span>
            @if ($unreadCount > 0)
                <span
                    class="ml-auto flex h-4 min-w-4 items-center justify-center rounded-full bg-error px-1 text-[10px] text-white"
                    aria-label="{{ $unreadCount }} unread changelog {{ Str::plural('entry', $unreadCount) }}">
                    {{ $unreadCount > 9 ? '9+' : $unreadCount }}
                </span>
            @endif
        </button>
    @else
        <!-- Custom Dropdown without arrow -->
        <div class="relative">
            <button @click="dropdownOpen = !dropdownOpen"
                class="relative p-2 dark:text-neutral-400 hover:dark:text-white transition-colors cursor-pointer"
                title="Preferences">
                <!-- Sliders Icon -->
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" title="Preferences">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                </svg>

                <!-- Unread Count Badge -->
                @if ($unreadCount > 0)
                    <span
                        class="absolute -top-1 -right-1 bg-error text-white text-xs rounded-full w-4.5 h-4.5 flex items-center justify-center">
                        {{ $unreadCount > 9 ? '9+' : $unreadCount }}
                    </span>
                @endif
            </button>

            <!-- Dropdown Menu -->
            <div x-show="dropdownOpen" x-transition:enter="ease-out duration-200"
                x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 -translate-y-2" class="absolute right-0 top-full mt-1 z-50 w-48" x-cloak>
                <div
                    class="p-1 bg-white border rounded-sm shadow-lg dark:bg-coolgray-200 dark:border-coolgray-300 border-neutral-300">
                    <div class="flex flex-col gap-1">
                        <!-- Width Section -->
                        <div
                            class="my-1 font-bold border-b dark:border-coolgray-500 border-neutral-300 dark:text-white text-md">
                            Width</div>
                        <button @click="switchWidth(); dropdownOpen = false"
                            class="px-1 dropdown-item-no-padding flex items-center gap-2" x-show="full === 'full'">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 6h16M4 12h16M4 18h7" />
                            </svg>
                            <span>Center</span>
                        </button>
                        <button @click="switchWidth(); dropdownOpen = false"
                            class="px-1 dropdown-item-no-padding flex items-center gap-2" x-show="full === 'center'">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                            <span>Full</span>
                        </button>

                        <!-- Zoom Section -->
                        <div
                            class="my-1 font-bold border-b dark:border-coolgray-500 border-neutral-300 dark:text-white text-md">
                            Zoom</div>
                        <button @click="setZoom(100); dropdownOpen = false"
                            class="px-1 dropdown-item-no-padding flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            <span>100%</span>
                        </button>
                        <button @click="setZoom(90); dropdownOpen = false"
                            class="px-1 dropdown-item-no-padding flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 10h4v4h-4v-4z" />
                            </svg>
                            <span>90%</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- What's New Modal -->
    @if ($showWhatsNewModal)
        <template x-teleport="body">
            <div class="fixed inset-0 z-[99] flex items-center justify-center p-3 sm:p-6"
                @keydown.escape.window="$wire.closeWhatsNewModal()">
                <div class="absolute inset-0 bg-black/55 backdrop-blur-[2px]" wire:click="closeWhatsNewModal"></div>

                <section
                    class="application-settings-form application-settings-section relative flex max-h-[calc(100dvh-3rem)] !w-full max-w-5xl flex-col overflow-hidden"
                    style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                    <header>
                    <div class="flex min-w-0 items-center gap-3">
                        <div
                            class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 dark:bg-white/[0.05] dark:text-fg-dim">
                            <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                    d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.091-3.091L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.091-3.091L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.091 3.091L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.091 3.091ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.456-2.456L14.25 6l1.035-.259a3.375 3.375 0 0 0 2.456-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z" />
                            </svg>
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3>What's new</h3>
                                <span
                                    class="rounded-md bg-neutral-100 px-1.5 py-0.5 text-[11px] font-medium text-neutral-500 dark:bg-white/[0.05] dark:text-fg-faint">
                                    {{ $currentVersion }}
                                </span>
                            </div>
                            <p class="mt-0.5 text-xs font-normal text-neutral-500 dark:text-fg-faint">
                                Product updates, fixes, and improvements.
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        @if (isDev())
                            <x-forms.button wire:click="manualFetchChangelog" class="gap-1.5">
                                <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                    aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                        d="M20 11a8.1 8.1 0 0 0-15.5-2M4 4v5h5m-5 4a8.1 8.1 0 0 0 15.5 2m.5 5v-5h-5" />
                                </svg>
                                Fetch Latest
                            </x-forms.button>
                        @endif
                        @if ($unreadCount > 0)
                            <x-forms.button @click="markAllEntriesAsRead">
                                Mark all read
                            </x-forms.button>
                        @endif
                        <button wire:click="closeWhatsNewModal"
                            class="flex size-7 cursor-pointer items-center justify-center rounded-md text-neutral-500 outline-0 hover:bg-neutral-100 hover:text-black focus-visible:ring-1 focus-visible:ring-accent dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                            aria-label="Close What's new">
                            <x-reicon name="x" class="size-4" />
                        </button>
                    </div>
                    </header>

                    <div class="application-settings-section-body flex min-h-0 flex-1 flex-col !p-0">
                    <div
                        class="flex shrink-0 items-center gap-3 border-b border-neutral-200 p-3 dark:border-white/[0.06]">
                        <div class="relative w-full max-w-sm">
                            <svg class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-neutral-400"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            <input x-model="search" @input="page = 1" type="search" placeholder="Search updates"
                                class="input !pl-9" />
                        </div>
                        <span class="ml-auto hidden text-xs text-neutral-500 sm:block dark:text-fg-faint"
                            x-text="`${filteredEntries.length} ${filteredEntries.length === 1 ? 'update' : 'updates'}`"></span>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto p-3 scrollbar sm:p-4">
                        <div x-show="filteredEntries.length > 0" class="flex flex-col gap-3">
                            <template x-for="entry in paginatedEntries" :key="entry.tag_name">
                                <article
                                    class="relative overflow-hidden rounded-lg border border-neutral-200 bg-white p-4 dark:border-white/[0.07] dark:bg-raised">
                                    <div x-show="!entry.is_read"
                                        class="absolute inset-y-0 left-0 w-0.5 bg-accent"></div>
                                    <div class="flex items-start gap-4">
                                        <div class="min-w-0 flex-1">
                                            <div class="mb-3 flex flex-wrap items-center gap-x-2 gap-y-1">
                                                <a :href="`https://github.com/coollabsio/coolify/releases/tag/${entry.tag_name}`"
                                                    target="_blank" rel="noopener noreferrer"
                                                    class="inline-flex min-w-0 items-center gap-1.5 text-sm font-semibold text-black hover:text-accent dark:text-fg"
                                                    x-show="entry.title">
                                                    <span class="truncate" x-text="entry.title"></span>
                                                    <x-external-link />
                                                </a>
                                                <span x-show="entry.tag_name === '{{ $currentVersion }}'"
                                                    class="rounded-md bg-success/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-success">
                                                    Current
                                                </span>
                                                <span class="text-xs text-neutral-500 dark:text-fg-faint"
                                                    x-text="new Date(entry.published_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })">
                                                </span>
                                            </div>
                                            <div
                                                class="max-w-none text-sm leading-6 text-neutral-600 dark:text-fg-dim [&_a]:font-medium [&_a]:text-accent [&_code]:rounded [&_code]:bg-neutral-100 [&_code]:px-1 [&_code]:py-0.5 dark:[&_code]:bg-white/[0.06] [&_li]:my-1 [&_ul]:ml-5 [&_ul]:list-disc"
                                                x-html="entry.content_html"></div>
                                        </div>
                                        <button x-show="!entry.is_read" @click="markEntryAsRead(entry.tag_name)"
                                            class="shrink-0 cursor-pointer rounded-md px-2 py-1 text-xs font-medium text-neutral-500 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                            title="Mark as read">
                                            Mark read
                                        </button>
                                    </div>
                                </article>
                            </template>
                        </div>

                        <div x-show="filteredEntries.length === 0"
                            class="flex min-h-64 flex-col items-center justify-center px-6 py-12 text-center">
                            <div
                                class="mb-3 flex size-10 items-center justify-center rounded-lg bg-neutral-100 text-neutral-400 dark:bg-white/[0.05] dark:text-fg-faint">
                                <x-reicon name="documentation" class="size-5" />
                            </div>
                            <h3 class="text-sm font-medium text-black dark:text-fg">No updates found</h3>
                            <p class="mt-1 max-w-sm text-sm text-neutral-500 dark:text-fg-faint">
                                <span x-show="search.trim() !== ''">No releases match your search.</span>
                                <span x-show="search.trim() === ''">There are no product updates available yet.</span>
                            </p>
                        </div>
                    </div>
                    <div x-cloak x-show="filteredEntries.length > 0"
                        class="flex min-h-14 shrink-0 items-center justify-between gap-3 border-t border-neutral-200 px-4 py-3 dark:border-white/[0.08]">
                        <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                            Showing
                            <span class="tabular-nums text-black dark:text-fg"
                                x-text="`${firstVisibleEntry}–${lastVisibleEntry}`"></span>
                            of
                            <span class="tabular-nums text-black dark:text-fg" x-text="filteredEntries.length"></span>
                        </p>
                        <div
                            class="inline-flex h-8 overflow-hidden rounded-lg border border-neutral-200 dark:border-white/[0.10]">
                            <button type="button"
                                class="flex w-10 items-center justify-center border-r border-neutral-200 text-neutral-500 transition-colors hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-35 dark:border-white/[0.10] dark:text-fg-dim dark:hover:bg-white/[0.06]"
                                aria-label="First page" title="First page" @click="goToPage(1)"
                                :disabled="page === 1">
                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M18 6L12 12L18 18M11 6L5 12L11 18" stroke="currentColor"
                                        stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <button type="button"
                                class="flex w-10 items-center justify-center border-r border-neutral-200 text-neutral-500 transition-colors hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-35 dark:border-white/[0.10] dark:text-fg-dim dark:hover:bg-white/[0.06]"
                                aria-label="Previous page" title="Previous page" @click="goToPage(page - 1)"
                                :disabled="page === 1">
                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M15 6L9 12L15 18" stroke="currentColor" stroke-width="1.8"
                                        stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <span
                                class="flex min-w-12 items-center justify-center border-r border-neutral-200 px-3 text-[13px] tabular-nums text-black dark:border-white/[0.10] dark:text-fg"
                                x-text="page"></span>
                            <button type="button"
                                class="flex w-10 items-center justify-center border-r border-neutral-200 text-neutral-500 transition-colors hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-35 dark:border-white/[0.10] dark:text-fg-dim dark:hover:bg-white/[0.06]"
                                aria-label="Next page" title="Next page" @click="goToPage(page + 1)"
                                :disabled="page === lastPage">
                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M9 6L15 12L9 18" stroke="currentColor" stroke-width="1.8"
                                        stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <button type="button"
                                class="flex w-10 items-center justify-center text-neutral-500 transition-colors hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-35 dark:text-fg-dim dark:hover:bg-white/[0.06]"
                                aria-label="Last page" title="Last page" @click="goToPage(lastPage)"
                                :disabled="page === lastPage">
                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M6 6L12 12L6 18M13 6L19 12L13 18" stroke="currentColor"
                                        stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                        </div>
                    </div>
                    </div>
                </section>
            </div>
        </template>
    @endif
</div>
