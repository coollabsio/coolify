<div x-data="{
    modalOpen: false,
    selectedIndex: -1,
    openModal() {
        this.modalOpen = true;
        this.selectedIndex = -1;
        @this.openSearchModal();
    },
    closeModal() {
        this.modalOpen = false;
        this.selectedIndex = -1;
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
        // Create named handlers for proper cleanup
        const openGlobalSearchHandler = () => this.openModal();
        const slashKeyHandler = (e) => {
            if (e.key === '/' && !['INPUT', 'TEXTAREA'].includes(e.target.tagName) && !this.modalOpen) {
                e.preventDefault();
                this.openModal();
            }
        };
        const cmdKHandler = (e) => {
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                if (this.modalOpen) {
                    this.closeModal();
                } else {
                    this.openModal();
                }
            }
        };
        const escapeKeyHandler = (e) => {
            if (e.key === 'Escape' && this.modalOpen) {
                this.closeModal();
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
    }
}">

    <!-- Modal overlay -->
    <template x-teleport="body">
        <div x-show="modalOpen" x-cloak
            class="fixed top-0 left-0 z-99 flex items-start justify-center w-screen h-screen pt-[20vh]">
            <div @click="closeModal()" class="absolute inset-0 w-full h-full bg-black/50 backdrop-blur-sm">
            </div>
            <div x-show="modalOpen" x-trap.inert="modalOpen" x-init="$watch('modalOpen', value => { document.body.style.overflow = value ? 'hidden' : '' })"
                x-transition:enter="ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-4 scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 scale-100" x-transition:leave="ease-in duration-150"
                x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                x-transition:leave-end="opacity-0 -translate-y-4 scale-95" class="relative w-full max-w-2xl mx-4"
                @click.stop>

                <!-- Search input (always visible) -->
                <div class="relative">
                    <div class="absolute inset-y-0 left-4 flex items-center pointer-events-none">
                        <svg class="w-5 h-5 text-neutral-400" xmlns="http://www.w3.org/2000/svg" fill="none"
                            viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                    <input type="text" wire:model.live.debounce.500ms="searchQuery"
                        placeholder="Search for resources, servers, projects, and environments" x-ref="searchInput"
                        x-init="$watch('modalOpen', value => { if (value) setTimeout(() => $refs.searchInput.focus(), 100) })"
                        class="w-full pl-12 pr-12 py-4 text-base bg-white dark:bg-coolgray-100 border-none rounded-lg shadow-xl ring-1 ring-neutral-200 dark:ring-coolgray-300 focus:ring-2 focus:ring-neutral-400 dark:focus:ring-coolgray-300 dark:text-white placeholder-neutral-400 dark:placeholder-neutral-500" />
                    <button @click="closeModal()"
                        class="absolute inset-y-0 right-2 flex items-center justify-center px-2 text-xs font-medium text-neutral-500 dark:text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200 rounded">
                        ESC
                    </button>
                </div>

                <!-- Search results (with background) -->
                @if (strlen($searchQuery) >= 1)
                    <div
                        class="mt-2 bg-white dark:bg-coolgray-100 rounded-lg shadow-xl ring-1 ring-neutral-200 dark:ring-coolgray-300 overflow-hidden">
                        <!-- Loading indicator -->
                        <div wire:loading.flex wire:target="searchQuery"
                            class="min-h-[200px] items-center justify-center p-8">
                            <div class="text-center">
                                <svg class="animate-spin mx-auto h-8 w-8 text-neutral-400"
                                    xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10"
                                        stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor"
                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                    </path>
                                </svg>
                                <p class="mt-2 text-sm text-neutral-600 dark:text-neutral-400">
                                    Searching...
                                </p>
                            </div>
                        </div>

                        <!-- Results content - hidden while loading -->
                        <div wire:loading.remove wire:target="searchQuery"
                            class="max-h-[60vh] overflow-y-auto scrollbar">
                            @if (strlen($searchQuery) >= 2 && count($searchResults) > 0)
                                <div class="py-2">
                                    @foreach ($searchResults as $index => $result)
                                        <a href="{{ $result['link'] ?? '#' }}"
                                            class="search-result-item block px-4 py-3 hover:bg-neutral-50 dark:hover:bg-coolgray-200 transition-colors focus:outline-none focus:bg-yellow-50 dark:focus:bg-yellow-900/20 border-transparent hover:border-coollabs focus:border-yellow-500 dark:focus:border-yellow-400">
                                            <div class="flex items-center justify-between gap-3">
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex items-center gap-2 mb-1">
                                                        <span
                                                            class="font-medium text-neutral-900 dark:text-white truncate">
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
                                                        <div
                                                            class="text-xs text-neutral-500 dark:text-neutral-400 mb-1">
                                                            {{ $result['project'] }} / {{ $result['environment'] }}
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
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        stroke-width="2" d="M9 5l7 7-7 7" />
                                                </svg>
                                            </div>
                                        </a>
                                    @endforeach
                                </div>
                            @elseif (strlen($searchQuery) >= 2 && count($searchResults) === 0)
                                <div class="flex items-center justify-center py-12 px-4">
                                    <div class="text-center">
                                        <p class="mt-4 text-sm font-medium text-neutral-900 dark:text-white">
                                            No results found
                                        </p>
                                        <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                                            Try different keywords or check the spelling
                                        </p>
                                    </div>
                                </div>
                            @elseif (strlen($searchQuery) > 0 && strlen($searchQuery) < 2)
                                <div class="flex items-center justify-center py-12 px-4">
                                    <div class="text-center">
                                        <p class="text-sm text-neutral-600 dark:text-neutral-400">
                                            Type at least 2 characters to search
                                        </p>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </template>
</div>
