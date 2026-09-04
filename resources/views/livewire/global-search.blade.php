<div x-data="{
    modalOpen: false,
    selectedIndex: -1,
    isSearching: false,
    isLoadingInitialData: false,
    showLoadingSpinner: false,
    spinnerTimer: null,
    closeResetTimer: null,
    isPaletteTransitioning: false,
    allSearchableItems: [],
    searchQuery: '',
    creatableItems: [],
    isCreateMode: false,
    // macOS/iOS use ⌘; Windows/Linux use Ctrl+
    modKeyLabel: (() => {
        const platform = navigator.userAgentData?.platform || navigator.platform || '';
        const ua = navigator.userAgent || '';
        return /Mac|iPhone|iPad|iPod/i.test(platform) || /Mac OS X|Macintosh/i.test(ua) ? '⌘' : 'Ctrl+';
    })(),
    serverTimingHudEnabled: localStorage.getItem('coolify.serverTimingHud.enabled') !== '0',
    developerCommandsEnabled: @js(app()->environment('local')),

    get showServerTimingCommand() {
        if (!this.developerCommandsEnabled) return false;
        const query = this.searchQuery.toLowerCase().trim();
        return query.length > 0 && ['server timing', 'timing hud', 'debug hud'].some(term => term.includes(query) || query.includes(term));
    },

    toggleServerTimingHud() {
        this.serverTimingHudEnabled = !this.serverTimingHudEnabled;
        localStorage.setItem('coolify.serverTimingHud.enabled', this.serverTimingHudEnabled ? '1' : '0');
        window.dispatchEvent(new CustomEvent('server-timing-hud-visibility-changed'));
        this.closeModal();
    },

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
        // Check if $wire is available (may not be after SPA navigation destroys/recreates component)
        if (typeof $wire === 'undefined' || !$wire) {
            console.warn('Global search: $wire not available, skipping open');
            return;
        }
        clearTimeout(this.closeResetTimer);
        clearTimeout(this.spinnerTimer);
        this.modalOpen = true;
        this.selectedIndex = -1;
        this.isLoadingInitialData = true;
        this.showLoadingSpinner = false;
        this.searchQuery = '';
        // Only show the spinner when loading takes longer than 150ms, so fast (cached) loads do not flash the icon
        this.spinnerTimer = setTimeout(() => {
            if (this.isLoadingInitialData) this.showLoadingSpinner = true;
        }, 150);
        $wire.openSearchModal().then(() => {
            this.allSearchableItems = $wire.allSearchableItems || [];
            this.creatableItems = $wire.creatableItems || [];
            clearTimeout(this.spinnerTimer);
            this.isLoadingInitialData = false;
            this.showLoadingSpinner = false;
            setTimeout(() => this.$refs.searchInput?.focus(), 50);
        }).catch(() => {
            // Handle case where component was destroyed during navigation
            clearTimeout(this.spinnerTimer);
            this.modalOpen = false;
            this.isLoadingInitialData = false;
            this.showLoadingSpinner = false;
        });
    },
    closeModal() {
        this.modalOpen = false;
        this.selectedIndex = -1;
        this.isSearching = false;
        // Keep the palette content intact until the leave animation (100ms) ends,
        // otherwise the panel collapses to header height while it fades out
        clearTimeout(this.closeResetTimer);
        this.closeResetTimer = setTimeout(() => {
            this.isLoadingInitialData = false;
            this.showLoadingSpinner = false;
            this.searchQuery = '';
            this.allSearchableItems = [];
            this.isPaletteTransitioning = false;
        }, 150);
    },
    runPaletteTransition(callback) {
        this.isPaletteTransitioning = true;
        return Promise.resolve(callback()).finally(() => {
            this.isPaletteTransitioning = false;
        });
    },
    preselectFirstResult() {
        this.$nextTick(() => {
            const results = Array.from(this.$el.querySelectorAll('.search-result-item'))
                .filter(item => item.offsetParent !== null);

            if (results.length === 0) {
                this.selectedIndex = -1;
                return;
            }

            this.selectedIndex = 0;
            results[0].focus();
            results[0].scrollIntoView({ block: 'nearest' });
        });
    },
    navigateResults(direction) {
        const results = Array.from(this.$el.querySelectorAll('.search-result-item'))
            .filter(item => item.offsetParent !== null);
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
                if (typeof $wire !== 'undefined' && $wire && $wire.isSelectingResource) {
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

                if (matchingItem && typeof $wire !== 'undefined' && $wire) {
                    this.runPaletteTransition(() => $wire.navigateToResource(matchingItem.type));
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
                    if (typeof $wire !== 'undefined' && $wire && $wire.isSelectingResource) {
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

        // Watch for auto-open resource (only if $wire is available)
        if (typeof $wire !== 'undefined' && $wire) {
            this.$watch('$wire.autoOpenResource', value => {
                if (value) {
                    // Close search modal first
                    this.closeModal();
                    if (value === 'server') {
                        window.location.href = '/servers/new';
                        return;
                    }
                    // Open the specific resource modal after a short delay
                    setTimeout(() => {
                        this.$dispatch('open-create-modal-' + value);
                        // Reset the value so it can trigger again
                        if (typeof $wire !== 'undefined' && $wire) {
                            $wire.set('autoOpenResource', null);
                        }
                    }, 150);
                }
            });
        }

        // Listen for closeSearchModal event from backend
        window.addEventListener('closeSearchModal', () => {
            this.closeModal();
        });
    }
}">

    <!-- Command palette -->
    <div x-cloak :class="modalOpen ? 'pointer-events-auto' : 'pointer-events-none'"
        class="fixed inset-0 z-99 flex items-start justify-center px-4 pt-[12vh]">
            <div x-show="modalOpen" @click="closeModal()"
                x-transition:enter="animate-in fade-in-0 duration-150"
                x-transition:leave="animate-out fade-out-0 duration-100 fill-mode-forwards"
                class="absolute inset-0 w-full h-full bg-black/50 backdrop-blur-[2px]">
            </div>
            <div x-show="modalOpen" x-trap.inert="modalOpen"
                x-transition:enter="animate-in fade-in-0 zoom-in-95 slide-in-from-top-2 duration-150"
                x-transition:leave="animate-out fade-out-0 zoom-out-95 slide-out-to-top-2 duration-100 fill-mode-forwards"
                class="command-palette relative mx-auto"
                @click.stop>

                <!-- Search input -->
                <div class="command-palette-header">
                    <span class="command-palette-header-icon" :class="showLoadingSpinner && 'is-loading'">
                        <svg x-show="!showLoadingSpinner" class="size-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path fill-rule="evenodd" clip-rule="evenodd"
                                d="M11.5 2.75C6.66751 2.75 2.75 6.66751 2.75 11.5C2.75 16.3325 6.66751 20.25 11.5 20.25C16.3325 20.25 20.25 16.3325 20.25 11.5C20.25 6.66751 16.3325 2.75 11.5 2.75ZM1.25 11.5C1.25 5.83908 5.83908 1.25 11.5 1.25C17.1609 1.25 21.75 5.83908 21.75 11.5C21.75 14.0605 20.8111 16.4017 19.2589 18.1982L22.5303 21.4697C22.8232 21.7626 22.8232 22.2374 22.5303 22.5303C22.2374 22.8232 21.7626 22.8232 21.4697 22.5303L18.1982 19.2589C16.4017 20.8111 14.0605 21.75 11.5 21.75C5.83908 21.75 1.25 17.1609 1.25 11.5Z"
                                fill="currentColor" />
                        </svg>
                        <svg x-show="showLoadingSpinner" x-cloak class="size-4 animate-spin" viewBox="0 0 24 24" fill="none"
                            aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle>
                            <path class="opacity-75" fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                            </path>
                        </svg>
                    </span>
                    <input type="text" x-model="searchQuery"
                        placeholder="Search resources, paths, everything (type new for create)..." x-ref="searchInput"
                        x-init="$watch('modalOpen', value => { if (value) setTimeout(() => $refs.searchInput.focus(), 100) })"
                        class="command-palette-input" autocomplete="off" spellcheck="false" />
                    <div class="command-palette-shortcuts">
                        <span class="command-palette-kbd">/</span>
                        <span class="command-palette-kbd" x-text="modKeyLabel + 'K'"></span>
                        <button type="button" @click="closeModal()" class="command-palette-kbd" title="Close">
                            ESC
                        </button>
                    </div>
                </div>

                <!-- Search results -->
                <div x-show="searchQuery.length >= 1" x-cloak class="command-palette-body relative">
                    @if (app()->environment('local'))
                        <div x-show="showServerTimingCommand && !$wire.isSelectingResource"
                            class="command-palette-section">
                            <div class="command-palette-group-label">Developer tools</div>
                            <button type="button" @click="toggleServerTimingHud()"
                                class="search-result-item command-palette-item">
                                <div class="command-palette-item-main">
                                    <div class="command-palette-item-title">
                                        <span class="command-palette-item-name">Toggle Server Timing HUD</span>
                                        <span class="command-palette-type-badge" x-text="serverTimingHudEnabled ? 'Enabled' : 'Disabled'"></span>
                                    </div>
                                    <div class="command-palette-item-meta"
                                        x-text="serverTimingHudEnabled ? 'Hide the local request timing overlay' : 'Show the local request timing overlay'"></div>
                                </div>
                                <x-reicon name="time-back" class="command-palette-item-chevron" />
                            </button>
                        </div>
                    @endif
                    <div x-show="isPaletteTransitioning" x-cloak
                        class="absolute inset-0 z-30 flex items-center justify-center bg-white/50 backdrop-blur-[2px] dark:bg-black/40">
                        <x-loading text="Loading…" />
                    </div>
                    @if ($isSelectingResource)
                        <!-- Resource selection flow -->
                        <div class="relative min-h-32">
                            <div class="command-palette-section transition-all"
                                wire:loading.class="pointer-events-none opacity-40 blur-[2px]"
                                wire:target="selectServer,selectDestination,selectProject,selectEnvironment">
                            @if ($selectedServerId === null)
                                <div x-init="preselectFirstResult()">
                                    <div class="command-palette-step-header">
                                        <button type="button" @click="runPaletteTransition(() => $wire.goBack())" class="command-palette-step-back"
                                            title="Back">
                                            <x-reicon name="arrow-right" class="size-3.5 rotate-180" />
                                        </button>
                                        <div class="min-w-0">
                                            <div class="command-palette-step-title">Select server</div>
                                            @if ($this->selectedResourceName)
                                                <div class="command-palette-step-subtitle">
                                                    for {{ $this->selectedResourceName }}
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                    @if ($loadingServers)
                                        <div class="command-palette-status">
                                            <svg class="size-3.5 animate-spin shrink-0" viewBox="0 0 24 24" fill="none"
                                                aria-hidden="true">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                    stroke-width="3"></circle>
                                                <path class="opacity-75" fill="currentColor"
                                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                                </path>
                                            </svg>
                                            <span>Loading servers…</span>
                                        </div>
                                    @elseif (count($availableServers) > 0)
                                        @foreach ($availableServers as $server)
                                            <button type="button" wire:click="selectServer({{ $server['id'] }}, true)"
                                                class="search-result-item command-palette-item">
                                                <div class="command-palette-item-main">
                                                    <div class="command-palette-item-name">{{ $server['name'] }}</div>
                                                    @if (!empty($server['description']))
                                                        <div class="command-palette-item-meta">{{ $server['description'] }}</div>
                                                    @endif
                                                </div>
                                                <x-reicon name="arrow-right" class="command-palette-item-chevron" />
                                            </button>
                                        @endforeach
                                    @else
                                        <div class="command-palette-status is-error">No servers available</div>
                                    @endif
                                </div>
                            @endif

                            @if ($selectedServerId !== null && $selectedDestinationUuid === null)
                                <div x-init="preselectFirstResult()">
                                    <div class="command-palette-step-header">
                                        <button type="button" @click="runPaletteTransition(() => $wire.goBack())" class="command-palette-step-back"
                                            title="Back">
                                            <x-reicon name="arrow-right" class="size-3.5 rotate-180" />
                                        </button>
                                        <div class="min-w-0">
                                            <div class="command-palette-step-title">Select destination</div>
                                            @if ($this->selectedResourceName)
                                                <div class="command-palette-step-subtitle">
                                                    for {{ $this->selectedResourceName }}
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                    @if ($loadingDestinations)
                                        <div class="command-palette-status">
                                            <svg class="size-3.5 animate-spin shrink-0" viewBox="0 0 24 24" fill="none"
                                                aria-hidden="true">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                    stroke-width="3"></circle>
                                                <path class="opacity-75" fill="currentColor"
                                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                                </path>
                                            </svg>
                                            <span>Loading destinations…</span>
                                        </div>
                                    @elseif (count($availableDestinations) > 0)
                                        @foreach ($availableDestinations as $destination)
                                            <button type="button"
                                                wire:click="selectDestination('{{ $destination['uuid'] }}', true)"
                                                class="search-result-item command-palette-item">
                                                <div class="command-palette-item-main">
                                                    <div class="command-palette-item-name">{{ $destination['name'] }}</div>
                                                    <div class="command-palette-item-meta">
                                                        Network: {{ $destination['network'] }}
                                                    </div>
                                                </div>
                                                <x-reicon name="arrow-right" class="command-palette-item-chevron" />
                                            </button>
                                        @endforeach
                                    @else
                                        <div class="command-palette-status is-error">No destinations available</div>
                                    @endif
                                </div>
                            @endif

                            @if ($selectedDestinationUuid !== null && $selectedProjectUuid === null)
                                <div x-init="preselectFirstResult()">
                                    <div class="command-palette-step-header">
                                        <button type="button" @click="runPaletteTransition(() => $wire.goBack())" class="command-palette-step-back"
                                            title="Back">
                                            <x-reicon name="arrow-right" class="size-3.5 rotate-180" />
                                        </button>
                                        <div class="min-w-0">
                                            <div class="command-palette-step-title">Select project</div>
                                            @if ($this->selectedResourceName)
                                                <div class="command-palette-step-subtitle">
                                                    for {{ $this->selectedResourceName }}
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                    @if ($loadingProjects)
                                        <div class="command-palette-status">
                                            <svg class="size-3.5 animate-spin shrink-0" viewBox="0 0 24 24" fill="none"
                                                aria-hidden="true">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                    stroke-width="3"></circle>
                                                <path class="opacity-75" fill="currentColor"
                                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                                </path>
                                            </svg>
                                            <span>Loading projects…</span>
                                        </div>
                                    @elseif (count($availableProjects) > 0)
                                        @foreach ($availableProjects as $project)
                                            <button type="button"
                                                wire:click="selectProject('{{ $project['uuid'] }}', true)"
                                                class="search-result-item command-palette-item">
                                                <div class="command-palette-item-main">
                                                    <div class="command-palette-item-name">{{ $project['name'] }}</div>
                                                    @if (!empty($project['description']))
                                                        <div class="command-palette-item-meta">{{ $project['description'] }}</div>
                                                    @endif
                                                </div>
                                                <x-reicon name="arrow-right" class="command-palette-item-chevron" />
                                            </button>
                                        @endforeach
                                    @else
                                        <div class="command-palette-status is-error">No projects available</div>
                                    @endif
                                </div>
                            @endif

                            @if ($selectedProjectUuid !== null && $selectedEnvironmentUuid === null)
                                <div x-init="preselectFirstResult()">
                                    <div class="command-palette-step-header">
                                        <button type="button" @click="runPaletteTransition(() => $wire.goBack())" class="command-palette-step-back"
                                            title="Back">
                                            <x-reicon name="arrow-right" class="size-3.5 rotate-180" />
                                        </button>
                                        <div class="min-w-0">
                                            <div class="command-palette-step-title">Select environment</div>
                                            @if ($this->selectedResourceName)
                                                <div class="command-palette-step-subtitle">
                                                    for {{ $this->selectedResourceName }}
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                    @if ($loadingEnvironments)
                                        <div class="command-palette-status">
                                            <svg class="size-3.5 animate-spin shrink-0" viewBox="0 0 24 24" fill="none"
                                                aria-hidden="true">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                    stroke-width="3"></circle>
                                                <path class="opacity-75" fill="currentColor"
                                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                                </path>
                                            </svg>
                                            <span>Loading environments…</span>
                                        </div>
                                    @elseif (count($availableEnvironments) > 0)
                                        @foreach ($availableEnvironments as $environment)
                                            <button type="button"
                                                wire:click="selectEnvironment('{{ $environment['uuid'] }}', true)"
                                                class="search-result-item command-palette-item">
                                                <div class="command-palette-item-main">
                                                    <div class="command-palette-item-name">{{ $environment['name'] }}</div>
                                                    @if (!empty($environment['description']))
                                                        <div class="command-palette-item-meta">
                                                            {{ $environment['description'] }}
                                                        </div>
                                                    @endif
                                                </div>
                                                <x-reicon name="arrow-right" class="command-palette-item-chevron" />
                                            </button>
                                        @endforeach
                                    @else
                                        <div class="command-palette-status is-error">No environments available</div>
                                    @endif
                                </div>
                            @endif
                            </div>
                            <div wire:loading.flex
                                wire:target="selectServer,selectDestination,selectProject,selectEnvironment"
                                class="absolute inset-0 z-10 hidden items-center justify-center bg-white/40 backdrop-blur-[1px] dark:bg-black/30">
                                <x-loading text="Loading selection…" />
                            </div>
                        </div>
                    @endif

                    <div wire:ignore>
                        <template x-if="searchQuery.length >= 1 && searchResults.length > 0 && !$wire.isSelectingResource">
                        <div class="command-palette-section">
                            <template x-if="filteredCreatableItems.length > 0">
                                <div class="command-palette-group-label">Existing resources</div>
                            </template>
                            <template x-for="(result, index) in searchResults" :key="index">
                                <a :href="result.link || '#'" class="search-result-item command-palette-item">
                                    <div class="command-palette-item-main">
                                        <div class="command-palette-item-title">
                                            <span class="command-palette-item-name" x-text="result.name"></span>
                                            <span class="command-palette-type-badge">
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
                                            <div class="command-palette-item-meta">
                                                <span x-text="result.project"></span> / <span x-text="result.environment"></span>
                                            </div>
                                        </template>
                                        <template x-if="result.description">
                                            <div class="command-palette-item-meta"
                                                x-text="result.description.length > 80 ? result.description.substring(0, 80) + '...' : result.description">
                                            </div>
                                        </template>
                                    </div>
                                    <svg class="command-palette-item-chevron" viewBox="0 0 24 24" fill="none"
                                        aria-hidden="true">
                                        <path d="M9 5l7 7-7 7" stroke="currentColor" stroke-width="1.5"
                                            stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </a>
                            </template>
                        </div>
                        </template>

                        <template x-if="filteredCreatableItems.length > 0 && !$wire.isSelectingResource">
                        <div>
                            <template x-for="[categoryName, items] in Object.entries(groupedCreatableItems)"
                                :key="categoryName">
                                <div class="command-palette-section">
                                    <div class="command-palette-group-label" x-text="categoryName"></div>
                                    <template x-for="item in items" :key="item.type">
                                        <button type="button" @click="runPaletteTransition(() => $wire.navigateToResource(item.type))"
                                            class="search-result-item command-palette-item">
                                            <template x-if="item.logo">
                                                <div class="command-palette-item-icon">
                                                    <img :src="item.logo.startsWith('http') ? item.logo : '/' + item.logo"
                                                        :alt="item.name"
                                                        x-on:error="if (item.logo_cdn_url && !$el.dataset.cdnTried) { $el.dataset.cdnTried = 'true'; $el.src = item.logo_cdn_url; } else if (item.logo_default_url && !$el.dataset.defaultTried) { $el.dataset.defaultTried = 'true'; $el.src = item.logo_default_url; }">
                                                </div>
                                            </template>
                                            <template x-if="!item.logo">
                                                <div class="command-palette-item-icon is-create">
                                                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                        <path d="M6 12H18" stroke="currentColor" stroke-width="1.5"
                                                            stroke-linecap="round" />
                                                        <path d="M12 18V6" stroke="currentColor" stroke-width="1.5"
                                                            stroke-linecap="round" />
                                                    </svg>
                                                </div>
                                            </template>
                                            <div class="command-palette-item-main">
                                                <div class="command-palette-item-title">
                                                    <span class="command-palette-item-name" x-text="item.name"></span>
                                                    <template x-if="item.amd_only">
                                                        <span class="command-palette-arch-badge"
                                                            title="This service only supports AMD64/x86_64 architecture">
                                                            AMD only
                                                        </span>
                                                    </template>
                                                    <template x-if="item.arm_only">
                                                        <span class="command-palette-arch-badge"
                                                            title="This service only supports ARM64/aarch64 architecture">
                                                            ARM only
                                                        </span>
                                                    </template>
                                                    <span class="command-palette-quickcommand"
                                                        x-text="(item.quickcommand || '').replace(/^\(type:\s*/i, '').replace(/\)$/, '')"
                                                        x-show="item.quickcommand"></span>
                                                </div>
                                                <div class="command-palette-item-meta" x-text="item.description"></div>
                                            </div>
                                            <svg class="command-palette-item-chevron" viewBox="0 0 24 24" fill="none"
                                                aria-hidden="true">
                                                <path d="M9 5l7 7-7 7" stroke="currentColor" stroke-width="1.5"
                                                    stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                        </button>
                                    </template>
                                </div>
                            </template>
                        </div>
                        </template>

                        <template
                            x-if="searchQuery.length >= 2 && searchResults.length === 0 && filteredCreatableItems.length === 0 && !showServerTimingCommand && !$wire.isSelectingResource && !$wire.autoOpenResource && !isLoadingInitialData">
                            <div class="command-palette-empty">
                                <p class="command-palette-empty-title">No results found</p>
                                <p class="command-palette-empty-desc">
                                    Try different keywords, or type <span class="font-medium">new</span> to create a resource.
                                </p>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
    </div>

    {{-- Create resource modals: always mounted with stable Livewire keys so
         Alpine teleport can open them. Do not loop @livewire() — Livewire
         ignores a third positional key arg and collides component ids. --}}
    @php
        $createModalShell = 'application-settings-form application-settings-section relative max-h-[calc(100dvh-2rem)] w-full lg:w-auto lg:min-w-2xl lg:max-w-4xl';
        $createModalClose = 'flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-md text-neutral-500 outline-0 transition-colors hover:bg-neutral-100 hover:text-black focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-accent dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg';
    @endphp

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
            })" class="fixed inset-0 z-99 overflow-y-auto" x-cloak>
                <div x-show="modalOpen" x-transition:enter="ease-out duration-100"
                    x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                    x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0" @click="modalOpen=false"
                    class="absolute inset-0 w-full h-full bg-black/50 backdrop-blur-[2px]"></div>
                <div @click.self="modalOpen=false"
                    class="relative flex min-h-full items-start justify-center p-4 sm:items-center">
                    <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen"
                        x-transition:enter="ease-out duration-100"
                        x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave="ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                        class="{{ $createModalShell }}"
                        style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                        <header class="flex-nowrap!">
                            <h3 class="min-w-0 flex-1 truncate">New project</h3>
                            <button type="button" @click="modalOpen=false" class="{{ $createModalClose }}">
                                <x-reicon name="x" class="size-4" />
                            </button>
                        </header>
                        <div class="application-settings-section-body min-h-0 flex-1 overflow-y-auto"
                            style="-webkit-overflow-scrolling: touch;">
                            <livewire:project.add-empty key="create-modal-project" />
                        </div>
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
            })" class="fixed inset-0 z-99 overflow-y-auto" x-cloak>
                <div x-show="modalOpen" x-transition:enter="ease-out duration-100"
                    x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                    x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0" @click="modalOpen=false"
                    class="absolute inset-0 w-full h-full bg-black/50 backdrop-blur-[2px]"></div>
                <div @click.self="modalOpen=false"
                    class="relative flex min-h-full items-start justify-center p-4 sm:items-center">
                    <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen"
                        x-transition:enter="ease-out duration-100"
                        x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave="ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                        class="{{ $createModalShell }}"
                        style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                        <header class="flex-nowrap!">
                            <h3 class="min-w-0 flex-1 truncate">New team</h3>
                            <button type="button" @click="modalOpen=false" class="{{ $createModalClose }}">
                                <x-reicon name="x" class="size-4" />
                            </button>
                        </header>
                        <div class="application-settings-section-body min-h-0 flex-1 overflow-y-auto"
                            style="-webkit-overflow-scrolling: touch;">
                            <livewire:team.create key="create-modal-team" />
                        </div>
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
            })" class="fixed inset-0 z-99 overflow-y-auto" x-cloak>
                <div x-show="modalOpen" x-transition:enter="ease-out duration-100"
                    x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                    x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0" @click="modalOpen=false"
                    class="absolute inset-0 w-full h-full bg-black/50 backdrop-blur-[2px]"></div>
                <div @click.self="modalOpen=false"
                    class="relative flex min-h-full items-start justify-center p-4 sm:items-center">
                    <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen"
                        x-transition:enter="ease-out duration-100"
                        x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave="ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                        class="{{ $createModalShell }}"
                        style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                        <header class="flex-nowrap!">
                            <h3 class="min-w-0 flex-1 truncate">New S3 storage</h3>
                            <button type="button" @click="modalOpen=false" class="{{ $createModalClose }}">
                                <x-reicon name="x" class="size-4" />
                            </button>
                        </header>
                        <div class="application-settings-section-body min-h-0 flex-1 overflow-y-auto"
                            style="-webkit-overflow-scrolling: touch;">
                            <livewire:storage.create key="create-modal-storage" />
                        </div>
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
            })" class="fixed inset-0 z-99 overflow-y-auto" x-cloak>
                <div x-show="modalOpen" x-transition:enter="ease-out duration-100"
                    x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                    x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0" @click="modalOpen=false"
                    class="absolute inset-0 w-full h-full bg-black/50 backdrop-blur-[2px]"></div>
                <div @click.self="modalOpen=false"
                    class="relative flex min-h-full items-start justify-center p-4 sm:items-center">
                    <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen"
                        x-transition:enter="ease-out duration-100"
                        x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave="ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                        class="{{ $createModalShell }}"
                        style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                        <header class="flex-nowrap!">
                            <h3 class="min-w-0 flex-1 truncate">New private key</h3>
                            <button type="button" @click="modalOpen=false" class="{{ $createModalClose }}">
                                <x-reicon name="x" class="size-4" />
                            </button>
                        </header>
                        <div class="application-settings-section-body min-h-0 flex-1 overflow-y-auto"
                            style="-webkit-overflow-scrolling: touch;">
                            <livewire:security.private-key.create key="create-modal-private-key" />
                        </div>
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
            })" class="fixed inset-0 z-99 overflow-y-auto" x-cloak>
                <div x-show="modalOpen" x-transition:enter="ease-out duration-100"
                    x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                    x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0" @click="modalOpen=false"
                    class="absolute inset-0 w-full h-full bg-black/50 backdrop-blur-[2px]"></div>
                <div @click.self="modalOpen=false"
                    class="relative flex min-h-full items-start justify-center p-4 sm:items-center">
                    <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen"
                        x-transition:enter="ease-out duration-100"
                        x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave="ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                        class="{{ $createModalShell }}"
                        style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                        <header class="flex-nowrap!">
                            <h3 class="min-w-0 flex-1 truncate">New GitHub app</h3>
                            <button type="button" @click="modalOpen=false" class="{{ $createModalClose }}">
                                <x-reicon name="x" class="size-4" />
                            </button>
                        </header>
                        <div class="application-settings-section-body min-h-0 flex-1 overflow-y-auto"
                            style="-webkit-overflow-scrolling: touch;">
                            <livewire:source.github.create key="create-modal-source" />
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>

</div>
