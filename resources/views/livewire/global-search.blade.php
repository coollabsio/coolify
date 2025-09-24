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
        // Listen for / key press globally
        document.addEventListener('keydown', (e) => {
            if (e.key === '/' && !['INPUT', 'TEXTAREA'].includes(e.target.tagName) && !this.modalOpen) {
                e.preventDefault();
                this.openModal();
            }
        });

        // Listen for Cmd+K or Ctrl+K globally
        document.addEventListener('keydown', (e) => {
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                if (this.modalOpen) {
                    this.closeModal();
                } else {
                    this.openModal();
                }
            }
        });

        // Listen for Escape key to close modal
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.modalOpen) {
                this.closeModal();
            }
        });

        // Listen for arrow keys when modal is open
        document.addEventListener('keydown', (e) => {
            if (!this.modalOpen) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                this.navigateResults('down');
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                this.navigateResults('up');
            }
        });
    }
}">
    <!-- Search bar in navbar  -->
    <div class="flex justify-center">
        <button @click="openModal()" type="button" title="Search (Press / or ⌘K)"
            class="flex items-center gap-1.5 px-2.5 py-1.5 bg-neutral-100 dark:bg-coolgray-100 border border-neutral-300 dark:border-coolgray-200 rounded-md hover:bg-neutral-200 dark:hover:bg-coolgray-200 transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-neutral-500 dark:text-neutral-400" fill="none"
                viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
            <kbd
                class="px-1 py-0.5 text-xs font-semibold text-neutral-500 dark:text-neutral-400 bg-neutral-200 dark:bg-coolgray-200 rounded">/</kbd>
        </button>
    </div>

    <!-- Modal overlay -->
    <template x-teleport="body">
        <div x-show="modalOpen" x-cloak
            class="fixed top-0 lg:pt-10 left-0 z-99 flex items-start justify-center w-screen h-screen">
            <div @click="closeModal()" class="absolute inset-0 w-full h-full bg-black/20 backdrop-blur-xs">
            </div>
            <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen" x-transition:enter="ease-out duration-100"
                x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave="ease-in duration-100"
                x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                class="relative w-full py-6 border rounded-sm min-w-full lg:min-w-[36rem] max-w-[48rem] bg-neutral-100 border-neutral-400 dark:bg-base px-7 dark:border-coolgray-300"
                @click.stop>

                <div class="flex justify-between items-center pb-3">
                    <h3 class="pr-8 text-2xl font-bold">Search</h3>
                    <button @click="closeModal()"
                        class="flex absolute top-2 right-2 justify-center items-center w-8 h-8 rounded-full dark:text-white hover:bg-coolgray-300">
                        <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                            stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="relative w-auto">
                    <input type="text" wire:model.live.debounce.500ms="searchQuery"
                        placeholder="Type to search for applications, services, databases, and servers..."
                        x-ref="searchInput" x-init="$watch('modalOpen', value => { if (value) setTimeout(() => $refs.searchInput.focus(), 100) })" class="w-full input mb-4" />

                    <!-- Search results -->
                    <div class="relative min-h-[330px] max-h-[400px] overflow-y-auto scrollbar">
                        <!-- Loading indicator -->
                        <div wire:loading.flex wire:target="searchQuery"
                            class="min-h-[330px] items-center justify-center">
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
                        <div wire:loading.remove wire:target="searchQuery">
                            @if (strlen($searchQuery) >= 2 && count($searchResults) > 0)
                                <div class="space-y-1 my-4 pb-4">
                                    @foreach ($searchResults as $index => $result)
                                        <a href="{{ $result['link'] ?? '#' }}"
                                            class="search-result-item block p-3 mx-1 hover:bg-neutral-200 dark:hover:bg-coolgray-200 transition-colors focus:outline-none focus:ring-1 focus:ring-coollabs focus:bg-neutral-100 dark:focus:bg-coolgray-200 ">
                                            <div class="flex items-center justify-between">
                                                <div class="flex-1">
                                                    <div class="flex items-center gap-2">
                                                        <span class="font-medium text-neutral-900 dark:text-white">
                                                            {{ $result['name'] }}
                                                        </span>
                                                        @if ($result['type'] === 'server')
                                                            <span
                                                                class="px-2 py-0.5 text-xs rounded bg-coolgray-100 text-white">
                                                                Server
                                                            </span>
                                                        @endif
                                                    </div>
                                                    <div class="flex items-center gap-2">
                                                        @if (!empty($result['project']) && !empty($result['environment']))
                                                            <span
                                                                class="text-xs text-neutral-500 dark:text-neutral-400">
                                                                {{ $result['project'] }} / {{ $result['environment'] }}
                                                            </span>
                                                        @endif
                                                        @if ($result['type'] === 'application')
                                                            <span
                                                                class="px-2 py-0.5 text-xs rounded bg-coolgray-100 text-white">
                                                                Application
                                                            </span>
                                                        @elseif ($result['type'] === 'service')
                                                            <span
                                                                class="px-2 py-0.5 text-xs rounded bg-coolgray-100 text-white">
                                                                Service
                                                            </span>
                                                        @elseif ($result['type'] === 'database')
                                                            <span
                                                                class="px-2 py-0.5 text-xs rounded bg-coolgray-100 text-white">
                                                                {{ ucfirst($result['subtype'] ?? 'Database') }}
                                                            </span>
                                                        @endif
                                                    </div>
                                                    @if (!empty($result['description']))
                                                        <div
                                                            class="text-sm text-neutral-600 dark:text-neutral-400 mt-0.5">
                                                            {{ Str::limit($result['description'], 100) }}
                                                        </div>
                                                    @endif
                                                </div>
                                                <svg xmlns="http://www.w3.org/2000/svg"
                                                    class="shrink-0 ml-2 h-4 w-4 text-neutral-400" fill="none"
                                                    viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        stroke-width="2" d="M9 5l7 7-7 7" />
                                                </svg>
                                            </div>
                                        </a>
                                    @endforeach
                                </div>
                            @elseif (strlen($searchQuery) >= 2 && count($searchResults) === 0)
                                <div class="flex items-center justify-center min-h-[330px]">
                                    <div class="text-center">
                                        <p class="text-sm text-neutral-600 dark:text-neutral-400">
                                            No results found for "<strong>{{ $searchQuery }}</strong>"
                                        </p>
                                        <p class="text-xs text-neutral-500 dark:text-neutral-500 mt-2">
                                            Try different keywords or check the spelling
                                        </p>
                                    </div>
                                </div>
                            @elseif (strlen($searchQuery) > 0 && strlen($searchQuery) < 2)
                                <div class="flex items-center justify-center min-h-[330px]">
                                    <div class="text-center">
                                        <p class="text-sm text-neutral-600 dark:text-neutral-400">
                                            Type at least 2 characters to search
                                        </p>
                                    </div>
                                </div>
                            @else
                                <div class="flex items-center justify-center min-h-[330px]">
                                    <div class="text-center">
                                        <p class="text-sm text-neutral-600 dark:text-neutral-400">
                                            Start typing to search
                                        </p>
                                        <p class="text-xs text-neutral-500 dark:text-neutral-500 mt-2">
                                            Search for applications, services, databases, and servers
                                        </p>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
</div>
</template>
</div>
