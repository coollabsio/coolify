<div x-data="{
    dropdownOpen: false,
    search: '',
    allEntries: [],
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
        let entries = this.allEntries;

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
    }
}" @click.outside="dropdownOpen = false">
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
                    <!-- What's New Section -->
                    @if ($unreadCount > 0)
                        <button wire:click="openWhatsNewModal" @click="dropdownOpen = false"
                            class="px-1 dropdown-item-no-padding flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span>What's New</span>
                            </div>
                            <span
                                class="bg-error text-white text-xs rounded-full w-5 h-5 flex items-center justify-center">
                                {{ $unreadCount > 9 ? '9+' : $unreadCount }}
                            </span>
                        </button>
                    @else
                        <button wire:click="openWhatsNewModal" @click="dropdownOpen = false"
                            class="px-1 dropdown-item-no-padding flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <span>Changelog</span>
                        </button>
                    @endif

                    <!-- Divider -->
                    <div class="border-b dark:border-coolgray-500 border-neutral-300"></div>

                    <!-- Theme Section -->
                    <div class="font-bold border-b dark:border-coolgray-500 border-neutral-300 dark:text-white pb-1">
                        Appearance</div>
                    <button @click="setTheme('dark'); dropdownOpen = false"
                        class="px-1 dropdown-item-no-padding flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                        </svg>
                        <span>Dark</span>
                    </button>
                    <button @click="setTheme('light'); dropdownOpen = false"
                        class="px-1 dropdown-item-no-padding flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                        </svg>
                        <span>Light</span>
                    </button>
                    <button @click="setTheme('system'); dropdownOpen = false"
                        class="px-1 dropdown-item-no-padding flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                        <span>System</span>
                    </button>

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

    <!-- What's New Modal -->
    @if ($showWhatsNewModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center py-6 px-4"
            @keydown.escape.window="$wire.closeWhatsNewModal()">
            <!-- Background overlay -->
            <div class="absolute inset-0 w-full h-full bg-black/20 backdrop-blur-xs" wire:click="closeWhatsNewModal">
            </div>

            <!-- Modal panel -->
            <div
                class="relative w-full h-full max-w-7xl py-6 border rounded-sm drop-shadow-sm bg-white border-neutral-200 dark:bg-base px-6 dark:border-coolgray-300 flex flex-col">
                <!-- Header -->
                <div class="flex items-center justify-between  pb-3">
                    <div>
                        <h3 class="text-2xl font-bold dark:text-white">
                            Changelog
                        </h3>
                        <p class="mt-1 text-sm dark:text-neutral-400">
                            Stay up to date with the latest features and improvements.
                        </p>
                        <p class="mt-1 text-xs dark:text-neutral-500">
                            Current version: <span class="font-semibold dark:text-neutral-300">{{ $currentVersion }}</span>
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        @if (isDev())
                            <x-forms.button wire:click="manualFetchChangelog"
                                class="bg-coolgray-200 hover:bg-coolgray-300">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                                Fetch Latest
                            </x-forms.button>
                        @endif
                        @if ($unreadCount > 0)
                            <x-forms.button @click="markAllEntriesAsRead">
                                Mark all as read
                            </x-forms.button>
                        @endif
                        <button wire:click="closeWhatsNewModal"
                            class="flex items-center justify-center w-8 h-8 rounded-full dark:text-white hover:bg-neutral-100 dark:hover:bg-coolgray-300 outline-0 cursor-pointer">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Search -->
                <div class="pb-4 border-b dark:border-coolgray-200 flex-shrink-0">
                    <div class="relative">
                        <input x-model="search" placeholder="Search updates..." class="input pl-10" />
                        <svg class="absolute left-3 top-2 w-4 h-4 dark:text-neutral-400" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                </div>

                <!-- Content -->
                <div class="py-4 flex-1 overflow-y-auto scrollbar">
                    <div x-show="filteredEntries.length > 0">
                        <div class="space-y-4">
                            <template x-for="entry in filteredEntries" :key="entry.tag_name">
                                <div class="relative p-4 border dark:border-coolgray-300 rounded-sm"
                                    :class="!entry.is_read ? 'dark:bg-coolgray-200 border-warning' : 'dark:bg-coolgray-100'">
                                    <div class="flex items-start justify-between">
                                        <div class="flex-1">
                                            <div class="flex items-center gap-2 mb-2">
                                                <span x-show="entry.title"
                                                    class="px-2 py-1 text-xs font-semibold dark:bg-coolgray-300 dark:text-neutral-200 rounded-sm"><a
                                                        :href="`https://github.com/coollabsio/coolify/releases/tag/${entry.tag_name}`"
                                                        target="_blank"
                                                        class="inline-flex items-center gap-1 hover:text-coolgray-500">
                                                        <span x-text="entry.title"></span>
                                                        <x-external-link />
                                                    </a></span>
                                                <span x-show="entry.tag_name === '{{ $currentVersion }}'"
                                                    class="px-2 py-1 text-xs font-semibold bg-success text-white rounded-sm">
                                                    CURRENT VERSION
                                                </span>
                                                <span class="text-xs dark:text-neutral-400"
                                                    x-text="new Date(entry.published_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })"></span>
                                            </div>
                                            <div class="dark:text-neutral-300 leading-relaxed max-w-none"
                                                x-html="entry.content_html">
                                            </div>
                                        </div>

                                        <button x-show="!entry.is_read" @click="markEntryAsRead(entry.tag_name)"
                                            class="ml-4 px-3 py-1 text-xs dark:text-neutral-400 hover:dark:text-white border dark:border-neutral-600 rounded hover:dark:bg-neutral-700 transition-colors cursor-pointer"
                                            title="Mark as read">
                                            mark as read
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div x-show="filteredEntries.length === 0" class="text-center py-8">
                        <svg class="mx-auto h-12 w-12 dark:text-neutral-400" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <h3 class="mt-2 text-sm font-medium dark:text-white">No updates found</h3>
                        <p class="mt-1 text-sm dark:text-neutral-400">
                            <span x-show="search.trim() !== ''">No updates match your search criteria.</span>
                            <span x-show="search.trim() === ''">There are no updates available at the moment.</span>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
