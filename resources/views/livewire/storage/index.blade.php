<div class="application-settings-form w-full">
    <x-slot:title>
        Storages | Coolify
    </x-slot>

    <header class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">S3 Storage</h1>
            <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                {{ $s3->count() }} {{ Str::plural('storage destination', $s3->count()) }} for backups
            </p>
        </div>
        @can('create', App\Models\S3Storage::class)
            <div class="w-fit shrink-0">
                <x-modal-input title="New S3 Storage" :closeOutside="false">
                    <x-slot:content>
                        <button type="button"
                            class="button button-highlighted">
                            <x-reicon name="plus" class="size-3.5" />
                            New storage
                        </button>
                    </x-slot:content>
                    <livewire:storage.create />
                </x-modal-input>
            </div>
        @endcan
    </header>

    @if ($s3->isEmpty())
        <x-empty title="No S3 storage yet"
            description="Add an S3-compatible destination to store backups outside your servers."
            icon-name="storages" />
    @else
        @php
            $items = $s3->map(fn ($storage) => [
                'name' => $storage->name,
                'description' => $storage->description ?: 'S3-compatible storage',
                'status' => $storage->is_usable ? 'Connected' : 'Not usable',
            ])->values();
        @endphp
        <div x-data="{
            search: '', viewMode: localStorage.getItem('coolify-s3-storages-view') || 'table', items: @js($items),
            get filteredItems() { const query = this.search.trim().toLowerCase(); return query ? this.items.filter(item => Object.values(item).some(value => String(value || '').toLowerCase().includes(query))) : this.items; },
            matches(values) { const query = this.search.trim().toLowerCase(); return !query || values.some(value => String(value || '').toLowerCase().includes(query)); },
            setViewMode(mode) { this.viewMode = mode; localStorage.setItem('coolify-s3-storages-view', mode); }
        }">
        @include('livewire.shared.list-search-controls', ['placeholder' => 'Search S3 storages', 'singular' => 'storage', 'plural' => 'storages'])
        <div x-cloak x-show="viewMode === 'grid'" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($s3 as $storage)
                <a x-show="matches(@js([$storage->name, $storage->description ?: 'S3-compatible storage', $storage->is_usable ? 'Connected' : 'Not usable']))" {{ wireNavigate() }} href="/storages/{{ $storage->uuid }}"
                    class="group flex min-h-28 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:no-underline hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]">
                    <div class="flex items-start gap-3">
                        <div
                            class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                            <x-reicon name="storages" class="size-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">
                                {{ $storage->name }}
                            </h2>
                            <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                {{ $storage->description ?: 'S3-compatible storage' }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-auto pt-4">
                        @if ($storage->is_usable)
                            <x-status-badge label="Connected" type="success" />
                        @else
                            <x-status-badge label="Not usable" type="error" />
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
        <div x-show="viewMode === 'table'" class="overflow-x-auto rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.025]">
            <div class="grid min-w-[620px] grid-cols-[minmax(0,1fr)_minmax(10rem,.8fr)_9rem] border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-faint"><div>Storage</div><div>Description</div><div>Status</div></div>
            @foreach ($s3 as $storage)
                <a x-show="matches(@js([$storage->name, $storage->description ?: 'S3-compatible storage', $storage->is_usable ? 'Connected' : 'Not usable']))" {{ wireNavigate() }} href="/storages/{{ $storage->uuid }}" class="grid min-h-14 min-w-[620px] grid-cols-[minmax(0,1fr)_minmax(10rem,.8fr)_9rem] items-center border-b border-neutral-200 px-4 py-2.5 text-[12px] transition-colors last:border-b-0 hover:bg-neutral-50 hover:no-underline dark:border-white/[0.07] dark:hover:bg-white/[0.025]">
                    <div class="truncate font-semibold text-black dark:text-fg">{{ $storage->name }}</div>
                    <div class="truncate text-neutral-500 dark:text-fg-dim">{{ $storage->description ?: 'S3-compatible storage' }}</div>
                    <div><x-status-badge :label="$storage->is_usable ? 'Connected' : 'Not usable'" :type="$storage->is_usable ? 'success' : 'error'" /></div>
                </a>
            @endforeach
        </div>
        @include('livewire.shared.list-search-empty', ['label' => 'S3 storages'])
        </div>
    @endif
</div>
