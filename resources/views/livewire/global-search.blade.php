<div x-data="{
    modalOpen: false,
    selectedIndex: -1,
    isSearching: false,
    isLoadingInitialData: false,
    allSearchableItems: [],
    searchQuery: '',
    creatableItems: [],
    isCreateMode: false,

    // Client-side search function
    get searchResults() {
        if (!this.searchQuery || this.searchQuery.length < 1) {
            return [];
        }

        // Don't execute search if data is still loading
        if (this.isLoadingInitialData) {
            return [];
        }

        const query = this.searchQuery.toLowerCase().trim();

        const results = this.allSearchableItems.filter(item => {
            if (!item.search_text) return false;
            return item.search_text.toLowerCase().includes(query);
        }).slice(0, 20);

        return results;
    },

    get filteredCreatableItems() {
        if (!this.searchQuery || this.searchQuery.length < 1) {
            return [];
        }

        // Don't execute search if data is still loading
        if (this.isLoadingInitialData) {
            return [];
        }

        const query = this.searchQuery.toLowerCase().trim();

        if (query === 'new') {
            return this.creatableItems;
        }

        return this.creatableItems.filter(item => {
            const searchText = `${item.name} ${item.description} ${item.type} ${item.category}`.toLowerCase();

            if (query.startsWith('new ')) {
                const queryWithoutNew = query.substring(4);
                return searchText.includes(queryWithoutNew) || searchText.includes(query);
            }

            return searchText.includes(query);
        });
    },

    get groupedCreatableItems() {
        const grouped = {};
        this.filteredCreatableItems.forEach(item => {
            const category = item.category || 'Other';
            if (!grouped[category]) {
                grouped[category] = [];
            }
            grouped[category].push(item);
        });
        return grouped;
    },

    openModal() {
        this.modalOpen = true;
        this.selectedIndex = -1;
        this.isLoadingInitialData = true;
        this.searchQuery = '';
        $wire.openSearchModal().then(() => {
            this.allSearchableItems = $wire.allSearchableItems || [];
            this.creatableItems = $wire.creatableItems || [];
            this.isLoadingInitialData = false;
            setTimeout(() => this.$refs.searchInput?.focus(), 50);
        });
    },
    closeModal() {
        this.modalOpen = false;
        this.selectedIndex = -1;
        this.isSearching = false;
        this.isLoadingInitialData = false;
        this.searchQuery = '';
        this.allSearchableItems = [];
        // Ensure scroll is restored
        document.body.style.overflow = '';
        @this.closeSearchModal();
    },
    navigateResults(direction) {
        const results = document.querySelectorAll('.search-result-item');
        if (results.length === 0) return;

        if (direction === 'down') {
            this.selectedIndex = Math.min(this.selectedIndex + 1, results.length - 1);
        } else if (direction === 'up') {
            this.selectedIndex = Math.max(this.selectedIndex - 1, -1);
        }

        if (this.selectedIndex >= 0 && this.selectedIndex < results.length) {
            results[this.selectedIndex].focus();
            results[this.selectedIndex].scrollIntoView({ block: 'nearest' });
        } else if (this.selectedIndex === -1) {
            this.$refs.searchInput?.focus();
        }
    },
    init() {
        // Listen for reset index event from Livewire
        Livewire.on('reset-selected-index', () => {
            this.selectedIndex = -1;
        });

        this.$watch('searchQuery', (value) => {
            this.selectedIndex = -1;
            const trimmed = value.trim().toLowerCase();

            if (trimmed === '') {
                if ($wire.isSelectingResource) {
                    $wire.cancelResourceSelection();
                }
                return;
            }

            const exactMatchCommands = [
                'new project', 'new server', 'new team', 'new storage', 'new s3',
                'new private key', 'new privatekey', 'new key',
                'new github app', 'new github', 'new source',
                'new public', 'new public git', 'new public repo', 'new public repository',
                'new private github', 'new private gh', 'new private deploy', 'new deploy key',
                'new dockerfile', 'new docker compose', 'new compose', 'new docker image', 'new image',
                'new postgresql', 'new postgres', 'new mysql', 'new mariadb',
                'new redis', 'new keydb', 'new dragonfly', 'new mongodb', 'new mongo', 'new clickhouse'
            ];
            if (exactMatchCommands.includes(trimmed)) {
                const matchingItem = this.creatableItems.find(item => {
                    const itemSearchText = `new ${item.name}`.toLowerCase();
                    const itemType = `new ${item.type}`.toLowerCase();
                    const itemTypeWithSpaces = item.type ? `new ${item.type.replace(/-/g, ' ')}` : '';

                    // Check if trimmed matches exactly or if the item's quickcommand includes this command
                    return itemSearchText === trimmed ||
                           itemType === trimmed ||
                           itemTypeWithSpaces === trimmed ||
                           (item.quickcommand && item.quickcommand.toLowerCase().includes(trimmed));
                });

                if (matchingItem) {
                    $wire.navigateToResource(matchingItem.type);
                }
            }
        });

        // Create named handlers for proper cleanup
        const openGlobalSearchHandler = () => this.openModal();
        const slashKeyHandler = (e) => {
            if (e.key === '/' && !['INPUT', 'TEXTAREA'].includes(e.target.tagName)) {
                e.preventDefault();
                if (!this.modalOpen) {
                    this.openModal();
                } else {
                    // If modal is open, focus the input
                    this.$refs.searchInput?.focus();
                    this.selectedIndex = -1;
                }
            }
        };
        const cmdKHandler = (e) => {
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                if (this.modalOpen) {
                    // If modal is open, focus the input instead of closing
                    this.$refs.searchInput?.focus();
                    this.selectedIndex = -1;
                } else {
                    this.openModal();
                }
            }
        };
        const escapeKeyHandler = (e) => {
            if (e.key === 'Escape' && this.modalOpen) {
                // If search query is empty, close the modal
                if (!this.searchQuery || this.searchQuery === '') {
                    // Check if we're in a selection state using Alpine-accessible Livewire state
                    if ($wire.isSelectingResource) {
                        $wire.cancelResourceSelection();
                        setTimeout(() => this.$refs.searchInput?.focus(), 100);
                    } else {
                        // Close the modal if in main menu
                        this.closeModal();
                    }
                } else {
                    // If search query has text, just clear it
                    this.searchQuery = '';
                    setTimeout(() => this.$refs.searchInput?.focus(), 100);
                }
            }
        };
        const arrowKeyHandler = (e) => {
            if (!this.modalOpen) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                this.navigateResults('down');
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                this.navigateResults('up');
            }
        };

        // Add event listeners
        window.addEventListener('open-global-search', openGlobalSearchHandler);
        document.addEventListener('keydown', slashKeyHandler);
        document.addEventListener('keydown', cmdKHandler);
        document.addEventListener('keydown', escapeKeyHandler);
        document.addEventListener('keydown', arrowKeyHandler);

        // Cleanup on component destroy
        this.$el.addEventListener('alpine:destroy', () => {
            window.removeEventListener('open-global-search', openGlobalSearchHandler);
            document.removeEventListener('keydown', slashKeyHandler);
            document.removeEventListener('keydown', cmdKHandler);
            document.removeEventListener('keydown', escapeKeyHandler);
            document.removeEventListener('keydown', arrowKeyHandler);
        });

        // Watch for auto-open resource
        this.$watch('$wire.autoOpenResource', value => {
            if (value) {
                // Close search modal first
                this.closeModal();
                // Open the specific resource modal after a short delay
                setTimeout(() => {
                    this.$dispatch('open-create-modal-' + value);
                    // Reset the value so it can trigger again
                    @this.set('autoOpenResource', null);
                }, 150);
            }
        });

        // Listen for closeSearchModal event from backend
        window.addEventListener('closeSearchModal', () => {
            this.closeModal();
        });
    }
}">

    <!-- Modal overlay -->
    <template x-teleport="body">
        <div x-show="modalOpen" x-cloak
            class="fixed top-0 left-0 z-99 flex items-start justify-center w-screen h-screen pt-[10vh]">
            <div @click="closeModal()" class="absolute inset-0 w-full h-full bg-black/50 backdrop-blur-sm">
            </div>
            <div x-show="modalOpen" x-trap.inert="modalOpen"
                x-init="$watch('modalOpen', value => { document.body.style.overflow = value ? 'hidden' : '' })"
                x-transition:enter="ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-4 scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 scale-100" x-transition:leave="ease-in duration-150"
                x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                x-transition:leave-end="opacity-0 -translate-y-4 scale-95" class="relative w-full max-w-2xl mx-4"
                @click.stop>

                <!-- Search input (always visible) -->
                <div class="relative">
                    <div class="absolute inset-y-0 left-4 flex items-center pointer-events-none">
                        <svg x-show="!isLoadingInitialData" class="w-5 h-5 text-neutral-400"
                            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        <svg x-show="isLoadingInitialData" x-cloak class="animate-spin h-5 w-5 text-warning"
                            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                            </circle>
                            <path class="opacity-75" fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                            </path>
                        </svg>
                    </div>
                    <input type="text" x-model="searchQuery"
                        placeholder="Search resources, paths, everything (type new for create)..." x-ref="searchInput"
                        x-init="$watch('modalOpen', value => { if (value) setTimeout(() => $refs.searchInput.focus(), 100) })"
                        class="w-full pl-12 pr-32 py-4 text-base bg-white dark:bg-coolgray-100 border-none rounded-lg shadow-xl ring-1 ring-neutral-200 dark:ring-coolgray-300 dark:text-white placeholder-neutral-400 dark:placeholder-neutral-500 focus-visible:outline-none focus-visible:border-l-4 focus-visible:border-l-coollabs dark:focus-visible:border-l-warning" />
                    <div class="absolute inset-y-0 right-2 flex items-center gap-2 pointer-events-none">
                        <span class="text-xs font-medium text-neutral-400 dark:text-neutral-500">
                            / or ⌘K to focus
                        </span>
                        <button @click="closeModal()"
                            class="pointer-events-auto px-2 py-1 text-xs font-medium text-neutral-500 dark:text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200 rounded">
                            ESC
                        </button>
                    </div>
                </div>

                <!-- Search results (with background) -->
                <div x-show="searchQuery.length >= 1" x-cloak
                    class="mt-2 bg-white dark:bg-coolgray-100 rounded-lg shadow-xl ring-1 ring-neutral-200 dark:ring-coolgray-300 overflow-hidden">
                    <!-- Results content -->
                    <div class="max-h-[60vh] overflow-y-auto scrollbar">
                        @if ($isSelectingResource)
                            <!-- Resource Selection Flow -->
                            <div class="p-6">
                                <!-- Server Selection -->
                                @if ($selectedServerId === null)
                                    <div class="mb-4" x-init="selectedIndex = -1">
                                        <div class="flex items-center gap-3 mb-3">
                                            <button type="button"
                                                @click="$wire.set('searchQuery', ''); setTimeout(() => $refs.searchInput.focus(), 100)"
                                                class="text-neutral-600 dark:text-neutral-400 hover:text-neutral-900 dark:hover:text-white">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none"
                                                    viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M15 19l-7-7 7-7" />
                                                </svg>
                                            </button>
                                            <div>
                                                <h2 class="text-base font-semibold text-neutral-900 dark:text-white">
                                                    Select Server
                                                </h2>
                                                @if ($this->selectedResourceName)
                                                    <div class="text-xs text-neutral-500 dark:text-neutral-400">
                                                        for {{ $this->selectedResourceName }}
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                        @if ($loadingServers)
                                            <div class="flex items-center gap-3 p-3 bg-neutral-50 dark:bg-coolgray-200 rounded-lg">
                                                <svg class="animate-spin h-5 w-5 text-yellow-500" xmlns="http://www.w3.org/2000/svg"
                                                    fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                        stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor"
                                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                                    </path>
                                                </svg>
                                                <span class="text-sm text-neutral-600 dark:text-neutral-400">Loading
                                                    servers...</span>
                                            </div>
                                        @elseif (count($availableServers) > 0)
                                            @foreach ($availableServers as $index => $server)
                                                <button type="button" wire:click="selectServer({{ $server['id'] }}, true)"
                                                    class="search-result-item w-full text-left block px-4 py-3 min-h-[4rem] hover:bg-yellow-50 dark:hover:bg-yellow-900/20 transition-colors focus:outline-none focus:bg-yellow-100 dark:focus:bg-yellow-900/30 border-b border-neutral-100 dark:border-coolgray-300 last:border-0">
                                                    <div class="flex items-center justify-between gap-3 min-h-[2.5rem]">
                                                        <div class="flex-1 min-w-0">
                                                            <div class="font-medium text-neutral-900 dark:text-white">
                                                                {{ $server['name'] }}
                                                            </div>
                                                            @if (!empty($server['description']))
                                                                <div class="text-xs text-neutral-500 dark:text-neutral-400">
                                                                    {{ $server['description'] }}
                                                                </div>
                                                            @else
                                                                <div class="text-xs text-transparent select-none">
                                                                    &nbsp;
                                                                </div>
                                                            @endif
                                                        </div>
                                                        <svg xmlns="http://www.w3.org/2000/svg"
                                                            class="shrink-0 h-5 w-5 text-yellow-500 dark:text-yellow-400" fill="none"
                                                            viewBox="0 0 24 24" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                                d="M9 5l7 7-7 7" />
                                                        </svg>
                                                    </div>
                                                </button>
                                            @endforeach
                                        @else
                                            <div
                                                class="p-3 bg-red-50 dark:bg-red-900/20 rounded-lg border border-red-200 dark:border-red-800">
                                                <p class="text-sm text-red-800 dark:text-red-200">No servers
                                                    available</p>
                                            </div>
                                        @endif
                                    </div>
                                @endif

                                <!-- Destination Selection -->
                                @if ($selectedServerId !== null && $selectedDestinationUuid === null)
                                    <div class="mb-4" x-init="selectedIndex = -1">
                                        <div class="flex items-center gap-3 mb-3">
                                            <button type="button"
                                                @click="$wire.set('searchQuery', ''); setTimeout(() => $refs.searchInput.focus(), 100)"
                                                class="text-neutral-600 dark:text-neutral-400 hover:text-neutral-900 dark:hover:text-white">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none"
                                                    viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M15 19l-7-7 7-7" />
                                                </svg>
                                            </button>
                                            <div>
                                                <h2 class="text-base font-semibold text-neutral-900 dark:text-white">
                                                    Select Destination
                                                </h2>
                                                @if ($this->selectedResourceName)
                                                    <div class="text-xs text-neutral-500 dark:text-neutral-400">
                                                        for {{ $this->selectedResourceName }}
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                        @if ($loadingDestinations)
                                            <div class="flex items-center gap-3 p-3 bg-neutral-50 dark:bg-coolgray-200 rounded-lg">
                                                <svg class="animate-spin h-5 w-5 text-yellow-500" xmlns="http://www.w3.org/2000/svg"
                                                    fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                        stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor"
                                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                                    </path>
                                                </svg>
                                                <span class="text-sm text-neutral-600 dark:text-neutral-400">Loading
                                                    destinations...</span>
                                            </div>
                                        @elseif (count($availableDestinations) > 0)
                                            @foreach ($availableDestinations as $index => $destination)
                                                <button type="button" wire:click="selectDestination('{{ $destination['uuid'] }}', true)"
                                                    class="search-result-item w-full text-left block px-4 py-3 min-h-[4rem] hover:bg-yellow-50 dark:hover:bg-yellow-900/20 transition-colors focus:outline-none focus:bg-yellow-100 dark:focus:bg-yellow-900/30 border-b border-neutral-100 dark:border-coolgray-300 last:border-0">
                                                    <div class="flex items-center justify-between gap-3 min-h-[2.5rem]">
                                                        <div class="flex-1 min-w-0">
                                                            <div class="font-medium text-neutral-900 dark:text-white">
                                                                {{ $destination['name'] }}
                                                            </div>
                                                            <div class="text-xs text-neutral-500 dark:text-neutral-400">
                                                                Network: {{ $destination['network'] }}
                                                            </div>
                                                        </div>
                                                        <svg xmlns="http://www.w3.org/2000/svg"
                                                            class="shrink-0 h-5 w-5 text-yellow-500 dark:text-yellow-400" fill="none"
                                                            viewBox="0 0 24 24" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                                d="M9 5l7 7-7 7" />
                                                        </svg>
                                                    </div>
                                                </button>
                                            @endforeach
                                        @else
                                            <div
                                                class="p-3 bg-red-50 dark:bg-red-900/20 rounded-lg border border-red-200 dark:border-red-800">
                                                <p class="text-sm text-red-800 dark:text-red-200">No destinations
                                                    available</p>
                                            </div>
                                        @endif
                                    </div>
                                @endif

                                <!-- Project Selection -->
                                @if ($selectedDestinationUuid !== null && $selectedProjectUuid === null)
                                    <div class="mb-4" x-init="selectedIndex = -1">
                                        <div class="flex items-center gap-3 mb-3">
                                            <button type="button"
                                                @click="$wire.set('searchQuery', ''); setTimeout(() => $refs.searchInput.focus(), 100)"
                                                class="text-neutral-600 dark:text-neutral-400 hover:text-neutral-900 dark:hover:text-white">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none"
                                                    viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M15 19l-7-7 7-7" />
                                                </svg>
                                            </button>
                                            <div>
                                                <h2 class="text-base font-semibold text-neutral-900 dark:text-white">
                                                    Select Project
                                                </h2>
                                                @if ($this->selectedResourceName)
                                                    <div class="text-xs text-neutral-500 dark:text-neutral-400">
                                                        for {{ $this->selectedResourceName }}
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                        @if ($loadingProjects)
                                            <div class="flex items-center gap-3 p-3 bg-neutral-50 dark:bg-coolgray-200 rounded-lg">
                                                <svg class="animate-spin h-5 w-5 text-yellow-500" xmlns="http://www.w3.org/2000/svg"
                                                    fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                        stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor"
                                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                                    </path>
                                                </svg>
                                                <span class="text-sm text-neutral-600 dark:text-neutral-400">Loading
                                                    projects...</span>
                                            </div>
                                        @elseif (count($availableProjects) > 0)
                                            @foreach ($availableProjects as $index => $project)
                                                <button type="button" wire:click="selectProject('{{ $project['uuid'] }}', true)"
                                                    class="search-result-item w-full text-left block px-4 py-3 min-h-[4rem] hover:bg-yellow-50 dark:hover:bg-yellow-900/20 transition-colors focus:outline-none focus:bg-yellow-100 dark:focus:bg-yellow-900/30 border-b border-neutral-100 dark:border-coolgray-300 last:border-0">
                                                    <div class="flex items-center justify-between gap-3 min-h-[2.5rem]">
                                                        <div class="flex-1 min-w-0">
                                                            <div class="font-medium text-neutral-900 dark:text-white">
                                                                {{ $project['name'] }}
                                                            </div>
                                                            @if (!empty($project['description']))
                                                                <div class="text-xs text-neutral-500 dark:text-neutral-400">
                                                                    {{ $project['description'] }}
                                                                </div>
                                                            @else
                                                                <div class="text-xs text-transparent select-none">
                                                                    &nbsp;
                                                                </div>
                                                            @endif
                                                        </div>
                                                        <svg xmlns="http://www.w3.org/2000/svg"
                                                            class="shrink-0 h-5 w-5 text-yellow-500 dark:text-yellow-400" fill="none"
                                                            viewBox="0 0 24 24" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                                d="M9 5l7 7-7 7" />
                                                        </svg>
                                                    </div>
                                                </button>
                                            @endforeach
                                        @else
                                            <div
                                                class="p-3 bg-red-50 dark:bg-red-900/20 rounded-lg border border-red-200 dark:border-red-800">
                                                <p class="text-sm text-red-800 dark:text-red-200">No projects
                                                    available</p>
                                            </div>
                                        @endif
                                    </div>
                                @endif

                                <!-- Environment Selection -->
                                @if ($selectedProjectUuid !== null && $selectedEnvironmentUuid === null)
                                    <div class="mb-4" x-init="selectedIndex = -1">
                                        <div class="flex items-center gap-3 mb-3">
                                            <button type="button"
                                                @click="$wire.set('searchQuery', ''); setTimeout(() => $refs.searchInput.focus(), 100)"
                                                class="text-neutral-600 dark:text-neutral-400 hover:text-neutral-900 dark:hover:text-white">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none"
                                                    viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M15 19l-7-7 7-7" />
                                                </svg>
                                            </button>
                                            <div>
                                                <h2 class="text-base font-semibold text-neutral-900 dark:text-white">
                                                    Select Environment
                                                </h2>
                                                @if ($this->selectedResourceName)
                                                    <div class="text-xs text-neutral-500 dark:text-neutral-400">
                                                        for {{ $this->selectedResourceName }}
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                        @if ($loadingEnvironments)
                                            <div class="flex items-center gap-3 p-3 bg-neutral-50 dark:bg-coolgray-200 rounded-lg">
                                                <svg class="animate-spin h-5 w-5 text-yellow-500" xmlns="http://www.w3.org/2000/svg"
                                                    fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                        stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor"
                                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                                    </path>
                                                </svg>
                                                <span class="text-sm text-neutral-600 dark:text-neutral-400">Loading
                                                    environments...</span>
                                            </div>
                                        @elseif (count($availableEnvironments) > 0)
                                            @foreach ($availableEnvironments as $index => $environment)
                                                <button type="button" wire:click="selectEnvironment('{{ $environment['uuid'] }}', true)"
                                                    class="search-result-item w-full text-left block px-4 py-3 min-h-[4rem] hover:bg-yellow-50 dark:hover:bg-yellow-900/20 transition-colors focus:outline-none focus:bg-yellow-100 dark:focus:bg-yellow-900/30 border-b border-neutral-100 dark:border-coolgray-300 last:border-0">
                                                    <div class="flex items-center justify-between gap-3 min-h-[2.5rem]">
                                                        <div class="flex-1 min-w-0">
                                                            <div class="font-medium text-neutral-900 dark:text-white">
                                                                {{ $environment['name'] }}
                                                            </div>
                                                            @if (!empty($environment['description']))
                                                                <div class="text-xs text-neutral-500 dark:text-neutral-400">
                                                                    {{ $environment['description'] }}
                                                                </div>
                                                            @else
                                                                <div class="text-xs text-transparent select-none">
                                                                    &nbsp;
                                                                </div>
                                                            @endif
                                                        </div>
                                                        <svg xmlns="http://www.w3.org/2000/svg"
                                                            class="shrink-0 h-5 w-5 text-yellow-500 dark:text-yellow-400" fill="none"
                                                            viewBox="0 0 24 24" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                                d="M9 5l7 7-7 7" />
                                                        </svg>
                                                    </div>
                                                </button>
                                            @endforeach
                                        @else
                                            <div
                                                class="p-3 bg-red-50 dark:bg-red-900/20 rounded-lg border border-red-200 dark:border-red-800">
                                                <p class="text-sm text-red-800 dark:text-red-200">No environments
                                                    available</p>
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @elseif ($isCreateMode && count($this->filteredCreatableItems) > 0 && !$autoOpenResource)
                            <!-- Create new resources section -->
                            <div class="py-2">
                                {{-- Show existing resources first if any match --}}
                                @php
                                    $existingResources = collect($searchResults)
                                        ->filter(fn($r) => !isset($r['is_creatable_suggestion']))
                                        ->count();
                                @endphp
                                @if ($existingResources > 0)
                                    <!-- Existing Resources Section -->
                                    <div class="px-4 pt-3 pb-1">
                                        <h4
                                            class="text-xs font-semibold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider">
                                            Existing Resources
                                        </h4>
                                    </div>
                                    @foreach ($searchResults as $result)
                                        @if (!isset($result['is_creatable_suggestion']))
                                            <a href="{{ $result['link'] ?? '#' }}"
                                                class="search-result-item block px-4 py-3 hover:bg-neutral-50 dark:hover:bg-coolgray-200 transition-colors focus:outline-none focus:bg-yellow-50 dark:focus:bg-yellow-900/20 border-transparent hover:border-coollabs focus:border-yellow-500 dark:focus:border-yellow-400">
                                                <div class="flex items-center justify-between gap-3">
                                                    <div class="flex-1 min-w-0">
                                                        <div class="flex items-center gap-2 mb-1">
                                                            <span class="font-medium text-neutral-900 dark:text-white truncate">
                                                                {{ $result['name'] }}
                                                            </span>
                                                            <span
                                                                class="px-2 py-0.5 text-xs rounded-full bg-neutral-100 dark:bg-coolgray-300 text-neutral-700 dark:text-neutral-300 shrink-0">
                                                                @if ($result['type'] === 'application')
                                                                    Application
                                                                @elseif ($result['type'] === 'service')
                                                                    Service
                                                                @elseif ($result['type'] === 'database')
                                                                    {{ ucfirst($result['subtype'] ?? 'Database') }}
                                                                @elseif ($result['type'] === 'server')
                                                                    Server
                                                                @elseif ($result['type'] === 'project')
                                                                    Project
                                                                @elseif ($result['type'] === 'environment')
                                                                    Environment
                                                                @endif
                                                            </span>
                                                        </div>
                                                        @if (!empty($result['project']) && !empty($result['environment']))
                                                            <div class="text-xs text-neutral-500 dark:text-neutral-400 mb-1">
                                                                {{ $result['project'] }} /
                                                                {{ $result['environment'] }}
                                                            </div>
                                                        @endif
                                                        @if (!empty($result['description']))
                                                            <div class="text-sm text-neutral-600 dark:text-neutral-400">
                                                                {{ Str::limit($result['description'], 80) }}
                                                            </div>
                                                        @endif
                                                    </div>
                                                    <svg xmlns="http://www.w3.org/2000/svg"
                                                        class="shrink-0 h-5 w-5 text-neutral-300 dark:text-neutral-600 self-center"
                                                        fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                            d="M9 5l7 7-7 7" />
                                                    </svg>
                                                </div>
                                            </a>
                                        @endif
                                    @endforeach
                                @endif

                                @php
                                    $grouped = collect($this->filteredCreatableItems)->groupBy('category');
                                @endphp

                                @foreach ($grouped as $category => $items)
                                    <!-- Category Header -->
                                    <div class="px-4 pt-3 pb-1">
                                        <h4
                                            class="text-xs font-semibold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider">
                                            {{ $category }}
                                        </h4>
                                    </div>

                                    <!-- Category Items -->
                                    @foreach ($items as $item)
                                        <button type="button" wire:click="navigateToResource('{{ $item['type'] }}')"
                                            class="search-result-item w-full text-left block px-4 py-3 hover:bg-yellow-50 dark:hover:bg-yellow-900/20 transition-colors focus:outline-none focus:bg-yellow-100 dark:focus:bg-yellow-900/30 border-transparent hover:border-yellow-500 focus:border-yellow-500">
                                            <div class="flex items-center justify-between gap-3">
                                                <div class="flex items-center gap-3 flex-1 min-w-0">
                                                    <div
                                                        class="flex-shrink-0 w-10 h-10 rounded-lg bg-yellow-100 dark:bg-yellow-900/40 flex items-center justify-center">
                                                        <svg xmlns="http://www.w3.org/2000/svg"
                                                            class="h-5 w-5 text-yellow-600 dark:text-yellow-400" fill="none"
                                                            viewBox="0 0 24 24" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                                d="M12 4v16m8-8H4" />
                                                        </svg>
                                                    </div>
                                                    <div class="flex-1 min-w-0">
                                                        <div class="flex items-center gap-2 mb-1">
                                                            <div class="font-medium text-neutral-900 dark:text-white truncate">
                                                                {{ $item['name'] }}
                                                            </div>
                                                            @if (isset($item['quickcommand']))
                                                                <span
                                                                    class="text-xs text-neutral-500 dark:text-neutral-400 shrink-0">{{ $item['quickcommand'] }}</span>
                                                            @endif
                                                        </div>
                                                        <div class="text-sm text-neutral-600 dark:text-neutral-400 truncate">
                                                            {{ $item['description'] }}
                                                        </div>
                                                    </div>
                                                </div>
                                                <svg xmlns="http://www.w3.org/2000/svg"
                                                    class="shrink-0 h-5 w-5 text-yellow-500 dark:text-yellow-400 self-center"
                                                    fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M9 5l7 7-7 7" />
                                                </svg>
                                            </div>
                                        </button>
                                    @endforeach
                                @endforeach
                            </div>
                        @endif

                        <template
                            x-if="searchQuery.length >= 1 && searchResults.length > 0 && !$wire.isSelectingResource">
                            <div class="py-2">
                                <template x-if="filteredCreatableItems.length > 0">
                                    <div class="px-4 pt-3 pb-1">
                                        <h4
                                            class="text-xs font-semibold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider">
                                            Existing Resources
                                        </h4>
                                    </div>
                                </template>
                                <template x-for="(result, index) in searchResults" :key="index">
                                    <a :href="result.link || '#'"
                                        class="search-result-item block px-4 py-3 hover:bg-neutral-50 dark:hover:bg-coolgray-200 transition-colors focus:outline-none focus:bg-yellow-50 dark:focus:bg-yellow-900/20 border-transparent hover:border-coollabs focus:border-yellow-500 dark:focus:border-yellow-400">
                                        <div class="flex items-center justify-between gap-3">
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center gap-2 mb-1">
                                                    <span class="font-medium text-neutral-900 dark:text-white truncate"
                                                        x-text="result.name">
                                                    </span>
                                                    <span
                                                        class="px-2 py-0.5 text-xs rounded-full bg-neutral-100 dark:bg-coolgray-300 text-neutral-700 dark:text-neutral-300 shrink-0">
                                                        <span x-show="result.type === 'navigation'">Navigation</span>
                                                        <span x-show="result.type === 'application'">Application</span>
                                                        <span x-show="result.type === 'service'">Service</span>
                                                        <span x-show="result.type === 'database'"
                                                            x-text="result.subtype ? result.subtype.charAt(0).toUpperCase() + result.subtype.slice(1) : 'Database'"></span>
                                                        <span x-show="result.type === 'server'">Server</span>
                                                        <span x-show="result.type === 'project'">Project</span>
                                                        <span x-show="result.type === 'environment'">Environment</span>
                                                    </span>
                                                </div>
                                                <template x-if="result.project && result.environment">
                                                    <div class="text-xs text-neutral-500 dark:text-neutral-400 mb-1">
                                                        <span x-text="result.project"></span> / <span
                                                            x-text="result.environment"></span>
                                                    </div>
                                                </template>
                                                <template x-if="result.description">
                                                    <div class="text-sm text-neutral-600 dark:text-neutral-400"
                                                        x-text="result.description.length > 80 ? result.description.substring(0, 80) + '...' : result.description">
                                                    </div>
                                                </template>
                                            </div>
                                            <svg xmlns="http://www.w3.org/2000/svg"
                                                class="shrink-0 h-5 w-5 text-neutral-300 dark:text-neutral-600 self-center"
                                                fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M9 5l7 7-7 7" />
                                            </svg>
                                        </div>
                                    </a>
                                </template>
                            </div>
                        </template>

                        <template x-if="filteredCreatableItems.length > 0 && !$wire.isSelectingResource">
                            <div class="py-2">
                                <template x-for="[categoryName, items] in Object.entries(groupedCreatableItems)"
                                    :key="categoryName">
                                    <div>
                                        <div class="px-4 pt-3 pb-1">
                                            <h4 class="text-xs font-semibold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider"
                                                x-text="categoryName">
                                            </h4>
                                        </div>

                                        <template x-for="item in items" :key="item.type">
                                            <button type="button" @click="$wire.navigateToResource(item.type)"
                                                class="search-result-item w-full text-left block px-4 py-3 hover:bg-yellow-50 dark:hover:bg-yellow-900/20 transition-colors focus:outline-none focus:bg-yellow-100 dark:focus:bg-yellow-900/30 border-transparent hover:border-yellow-500 focus:border-yellow-500">
                                                <div class="flex items-center justify-between gap-3">
                                                    <div class="flex items-center gap-3 flex-1 min-w-0">
                                                        <div
                                                            class="flex-shrink-0 w-10 h-10 rounded-lg bg-yellow-100 dark:bg-yellow-900/40 flex items-center justify-center">
                                                            <svg xmlns="http://www.w3.org/2000/svg"
                                                                class="h-5 w-5 text-yellow-600 dark:text-yellow-400"
                                                                fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                                    stroke-width="2" d="M12 4v16m8-8H4" />
                                                            </svg>
                                                        </div>
                                                        <div class="flex-1 min-w-0">
                                                            <div class="flex items-center gap-2 mb-1">
                                                                <div class="font-medium text-neutral-900 dark:text-white truncate"
                                                                    x-text="item.name">
                                                                </div>
                                                                <span
                                                                    class="text-xs text-neutral-500 dark:text-neutral-400 shrink-0"
                                                                    x-text="item.quickcommand"
                                                                    x-show="item.quickcommand">
                                                                </span>
                                                            </div>
                                                            <div class="text-sm text-neutral-600 dark:text-neutral-400 truncate"
                                                                x-text="item.description">
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <svg xmlns="http://www.w3.org/2000/svg"
                                                        class="shrink-0 h-5 w-5 text-yellow-500 dark:text-yellow-400 self-center"
                                                        fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            stroke-width="2" d="M9 5l7 7-7 7" />
                                                    </svg>
                                                </div>
                                            </button>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <template
                            x-if="searchQuery.length >= 2 && searchResults.length === 0 && filteredCreatableItems.length === 0 && !$wire.isSelectingResource && !$wire.autoOpenResource && !isLoadingInitialData">
                            <div class="flex items-center justify-center py-12 px-4">
                                <div class="text-center">
                                    <p class="mt-4 text-sm font-medium text-neutral-900 dark:text-white">
                                        No results found
                                    </p>
                                    <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                                        Try different keywords or check the spelling
                                    </p>
                                    <p class="mt-2 text-xs text-neutral-400 dark:text-neutral-500">
                                        💡 Tip: Search for service names like "wordpress", "postgres", or "redis"
                                    </p>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </template>

    <!-- Create Resource Modals - Always rendered so they're available when triggered -->
    <div x-data="{ modalOpen: false }" @open-create-modal-project.window="modalOpen = true"
        @keydown.window.escape="modalOpen=false" class="relative w-auto h-auto">
        <template x-teleport="body">
            <div x-show="modalOpen" x-init="$watch('modalOpen', value => {
                if (value) {
                    setTimeout(() => {
                        const firstInput = $el.querySelector('input, textarea, select');
                        if (firstInput) firstInput.focus();
                    }, 200);
                }
            })" class="fixed top-0 left-0 lg:px-0 px-4 z-99 flex items-center justify-center w-screen h-screen">
                <div x-show="modalOpen" x-transition:enter="ease-out duration-100" x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-100"
                    x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" @click="modalOpen=false"
                    class="absolute inset-0 w-full h-full bg-black/20 backdrop-blur-xs"></div>
                <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen" x-transition:enter="ease-out duration-100"
                    x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                    x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave="ease-in duration-100"
                    x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                    class="relative w-full py-6 border rounded-sm drop-shadow-sm min-w-full lg:min-w-[36rem] max-w-fit bg-white border-neutral-200 dark:bg-base px-6 dark:border-coolgray-300">
                    <div class="flex items-center justify-between pb-3">
                        <h3 class="text-2xl font-bold">New Project</h3>
                        <button @click="modalOpen=false"
                            class="absolute top-0 right-0 flex items-center justify-center w-8 h-8 mt-5 mr-5 rounded-full dark:text-white hover:bg-neutral-100 dark:hover:bg-coolgray-300 outline-0 focus-visible:ring-2 focus-visible:ring-coollabs dark:focus-visible:ring-warning">
                            <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <div class="relative flex items-center justify-center w-auto">
                        <livewire:project.add-empty key="create-modal-project" />
                    </div>
                </div>
            </div>
        </template>
    </div>

    <div x-data="{ modalOpen: false }" @open-create-modal-server.window="modalOpen = true"
        @keydown.window.escape="modalOpen=false" class="relative w-auto h-auto">
        <template x-teleport="body">
            <div x-show="modalOpen" x-init="$watch('modalOpen', value => {
                if (value) {
                    setTimeout(() => {
                        const firstInput = $el.querySelector('input, textarea, select');
                        if (firstInput) firstInput.focus();
                    }, 200);
                }
            })" class="fixed top-0 left-0 lg:px-0 px-4 z-99 flex items-center justify-center w-screen h-screen">
                <div x-show="modalOpen" x-transition:enter="ease-out duration-100" x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-100"
                    x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" @click="modalOpen=false"
                    class="absolute inset-0 w-full h-full bg-black/20 backdrop-blur-xs"></div>
                <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen" x-transition:enter="ease-out duration-100"
                    x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                    x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave="ease-in duration-100"
                    x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                    class="relative w-full py-6 border rounded-sm drop-shadow-sm min-w-full lg:min-w-[36rem] max-w-fit bg-white border-neutral-200 dark:bg-base px-6 dark:border-coolgray-300">
                    <div class="flex items-center justify-between pb-3">
                        <h3 class="text-2xl font-bold">New Server</h3>
                        <button @click="modalOpen=false"
                            class="absolute top-0 right-0 flex items-center justify-center w-8 h-8 mt-5 mr-5 rounded-full dark:text-white hover:bg-neutral-100 dark:hover:bg-coolgray-300 outline-0 focus-visible:ring-2 focus-visible:ring-coollabs dark:focus-visible:ring-warning">
                            <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <div class="relative flex items-center justify-center w-auto">
                        <livewire:server.create key="create-modal-server" />
                    </div>
                </div>
            </div>
        </template>
    </div>

    <div x-data="{ modalOpen: false }" @open-create-modal-team.window="modalOpen = true"
        @keydown.window.escape="modalOpen=false" class="relative w-auto h-auto">
        <template x-teleport="body">
            <div x-show="modalOpen" x-init="$watch('modalOpen', value => {
                if (value) {
                    setTimeout(() => {
                        const firstInput = $el.querySelector('input, textarea, select');
                        if (firstInput) firstInput.focus();
                    }, 200);
                }
            })" class="fixed top-0 left-0 lg:px-0 px-4 z-99 flex items-center justify-center w-screen h-screen">
                <div x-show="modalOpen" x-transition:enter="ease-out duration-100" x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-100"
                    x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" @click="modalOpen=false"
                    class="absolute inset-0 w-full h-full bg-black/20 backdrop-blur-xs"></div>
                <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen" x-transition:enter="ease-out duration-100"
                    x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                    x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave="ease-in duration-100"
                    x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                    class="relative w-full py-6 border rounded-sm drop-shadow-sm min-w-full lg:min-w-[36rem] max-w-fit bg-white border-neutral-200 dark:bg-base px-6 dark:border-coolgray-300">
                    <div class="flex items-center justify-between pb-3">
                        <h3 class="text-2xl font-bold">New Team</h3>
                        <button @click="modalOpen=false"
                            class="absolute top-0 right-0 flex items-center justify-center w-8 h-8 mt-5 mr-5 rounded-full dark:text-white hover:bg-neutral-100 dark:hover:bg-coolgray-300 outline-0 focus-visible:ring-2 focus-visible:ring-coollabs dark:focus-visible:ring-warning">
                            <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <div class="relative flex items-center justify-center w-auto">
                        <livewire:team.create key="create-modal-team" />
                    </div>
                </div>
            </div>
        </template>
    </div>

    <div x-data="{ modalOpen: false }" @open-create-modal-storage.window="modalOpen = true"
        @keydown.window.escape="modalOpen=false" class="relative w-auto h-auto">
        <template x-teleport="body">
            <div x-show="modalOpen" x-init="$watch('modalOpen', value => {
                if (value) {
                    setTimeout(() => {
                        const firstInput = $el.querySelector('input, textarea, select');
                        if (firstInput) firstInput.focus();
                    }, 200);
                }
            })" class="fixed top-0 left-0 lg:px-0 px-4 z-99 flex items-center justify-center w-screen h-screen">
                <div x-show="modalOpen" x-transition:enter="ease-out duration-100" x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-100"
                    x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" @click="modalOpen=false"
                    class="absolute inset-0 w-full h-full bg-black/20 backdrop-blur-xs"></div>
                <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen" x-transition:enter="ease-out duration-100"
                    x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                    x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave="ease-in duration-100"
                    x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                    class="relative w-full py-6 border rounded-sm drop-shadow-sm min-w-full lg:min-w-[36rem] max-w-fit bg-white border-neutral-200 dark:bg-base px-6 dark:border-coolgray-300">
                    <div class="flex items-center justify-between pb-3">
                        <h3 class="text-2xl font-bold">New S3 Storage</h3>
                        <button @click="modalOpen=false"
                            class="absolute top-0 right-0 flex items-center justify-center w-8 h-8 mt-5 mr-5 rounded-full dark:text-white hover:bg-neutral-100 dark:hover:bg-coolgray-300 outline-0 focus-visible:ring-2 focus-visible:ring-coollabs dark:focus-visible:ring-warning">
                            <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <div class="relative flex items-center justify-center w-auto">
                        <livewire:storage.create key="create-modal-storage" />
                    </div>
                </div>
            </div>
        </template>
    </div>

    <div x-data="{ modalOpen: false }" @open-create-modal-private-key.window="modalOpen = true"
        @keydown.window.escape="modalOpen=false" class="relative w-auto h-auto">
        <template x-teleport="body">
            <div x-show="modalOpen" x-init="$watch('modalOpen', value => {
                if (value) {
                    setTimeout(() => {
                        const firstInput = $el.querySelector('input, textarea, select');
                        if (firstInput) firstInput.focus();
                    }, 200);
                }
            })" class="fixed top-0 left-0 lg:px-0 px-4 z-99 flex items-center justify-center w-screen h-screen">
                <div x-show="modalOpen" x-transition:enter="ease-out duration-100" x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-100"
                    x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" @click="modalOpen=false"
                    class="absolute inset-0 w-full h-full bg-black/20 backdrop-blur-xs"></div>
                <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen" x-transition:enter="ease-out duration-100"
                    x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                    x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave="ease-in duration-100"
                    x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                    class="relative w-full py-6 border rounded-sm drop-shadow-sm min-w-full lg:min-w-[36rem] max-w-fit bg-white border-neutral-200 dark:bg-base px-6 dark:border-coolgray-300">
                    <div class="flex items-center justify-between pb-3">
                        <h3 class="text-2xl font-bold">New Private Key</h3>
                        <button @click="modalOpen=false"
                            class="absolute top-0 right-0 flex items-center justify-center w-8 h-8 mt-5 mr-5 rounded-full dark:text-white hover:bg-neutral-100 dark:hover:bg-coolgray-300 outline-0 focus-visible:ring-2 focus-visible:ring-coollabs dark:focus-visible:ring-warning">
                            <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <div class="relative flex items-center justify-center w-auto">
                        <livewire:security.private-key.create key="create-modal-private-key" />
                    </div>
                </div>
            </div>
        </template>
    </div>

    <div x-data="{ modalOpen: false }" @open-create-modal-source.window="modalOpen = true"
        @keydown.window.escape="modalOpen=false" class="relative w-auto h-auto">
        <template x-teleport="body">
            <div x-show="modalOpen" x-init="$watch('modalOpen', value => {
                if (value) {
                    setTimeout(() => {
                        const firstInput = $el.querySelector('input, textarea, select');
                        if (firstInput) firstInput.focus();
                    }, 200);
                }
            })" class="fixed top-0 left-0 lg:px-0 px-4 z-99 flex items-center justify-center w-screen h-screen">
                <div x-show="modalOpen" x-transition:enter="ease-out duration-100" x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-100"
                    x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" @click="modalOpen=false"
                    class="absolute inset-0 w-full h-full bg-black/20 backdrop-blur-xs"></div>
                <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen" x-transition:enter="ease-out duration-100"
                    x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                    x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave="ease-in duration-100"
                    x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                    class="relative w-full py-6 border rounded-sm drop-shadow-sm min-w-full lg:min-w-[36rem] max-w-fit bg-white border-neutral-200 dark:bg-base px-6 dark:border-coolgray-300">
                    <div class="flex items-center justify-between pb-3">
                        <h3 class="text-2xl font-bold">New GitHub App</h3>
                        <button @click="modalOpen=false"
                            class="absolute top-0 right-0 flex items-center justify-center w-8 h-8 mt-5 mr-5 rounded-full dark:text-white hover:bg-neutral-100 dark:hover:bg-coolgray-300 outline-0 focus-visible:ring-2 focus-visible:ring-coollabs dark:focus-visible:ring-warning">
                            <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <div class="relative flex items-center justify-center w-auto">
                        <livewire:source.github.create key="create-modal-source" />
                    </div>
                </div>
            </div>
        </template>
    </div>

</div>