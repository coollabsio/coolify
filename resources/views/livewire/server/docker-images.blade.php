<div wire:init="load">
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Images | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="docker-images" />

        <div class="application-settings-form w-full min-w-0">
            <x-application.settings-section id="server-docker-images-section" title="Images"
                helper="Docker images stored on this server. Images that no container uses can be deleted here."
                flush>
                <x-slot:actions>
                    @if ($usage)
                        <x-status-badge :status="'Total ' . $usage['size']" type="neutral" />
                        <x-status-badge :status="'Reclaimable ' . $usage['reclaimable']" type="warning" />
                    @endif
                    <x-forms.button wire:click="load">
                        <x-reicon name="refresh" class="size-3.5" />
                        Refresh
                    </x-forms.button>
                </x-slot:actions>

                <div class="border-b border-neutral-200 p-3 dark:border-white/[0.08]">
                    <div class="relative w-full max-w-sm">
                        <x-reicon name="search"
                            class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                        <input wire:model.live.debounce.300ms="search" type="search"
                            placeholder="Search images by repository, tag or ID" aria-label="Search images"
                            class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-8! pl-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-accent! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint">
                    </div>
                </div>

                @if (!$loaded)
                    <div class="p-6">
                        <x-loading text="Reading images from the Docker daemon" />
                    </div>
                @elseif ($visibleImages->isEmpty())
                    <div class="p-6">
                        <x-empty size="sm" :title="trim($search) !== '' ? 'No matching images' : 'No images found'" :description="trim($search) !== ''
                            ? 'Try another repository, tag or ID, or clear the search.'
                            : 'Images pulled or built on this server will appear here.'" icon-name="layers" />
                    </div>
                @else
                    <div class="data-table">
                        <div class="data-table-header server-images-table-grid">
                            <span>Repository</span>
                            <span>Tag</span>
                            <span>Image ID</span>
                            <span>Created</span>
                            <span>Size</span>
                            <span>Used by</span>
                            <span>Actions</span>
                        </div>
                        @foreach ($visibleImages as $image)
                            <div wire:key="image-{{ $image['reference'] }}"
                                class="data-table-row server-images-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]">
                                <div class="min-w-0 truncate text-[12px] font-medium text-neutral-950 dark:text-fg"
                                    title="{{ $image['repository'] }}">
                                    {{ $image['repository'] }}
                                </div>
                                <div class="min-w-0 truncate text-[11px] text-neutral-600 dark:text-fg-dim">
                                    {{ $image['tag'] }}
                                </div>
                                <div class="flex min-w-0 items-center gap-1">
                                    <span class="truncate font-mono text-[11px] text-neutral-600 dark:text-fg-dim">
                                        {{ $image['short_id'] }}
                                    </span>
                                    <x-copy-button :value="$image['id']" label="Copy image ID" />
                                </div>
                                <div class="truncate text-[11px] text-neutral-600 dark:text-fg-dim">
                                    {{ $image['created'] }}
                                </div>
                                <div class="text-[11px] text-neutral-600 dark:text-fg-dim">{{ $image['size'] }}</div>
                                <div class="min-w-0">
                                    @if ($image['containers'] === [])
                                        <x-status-badge status="Unused" type="warning" />
                                    @else
                                        <span class="block truncate text-[11px] text-neutral-600 dark:text-fg-dim"
                                            title="{{ implode(', ', $image['containers']) }}">
                                            {{ implode(', ', $image['containers']) }}
                                        </span>
                                    @endif
                                </div>
                                <div>
                                    @if ($image['containers'] === [])
                                        <x-forms.button canGate="update" :canResource="$server" isError
                                            wire:click="delete('{{ $image['reference'] }}')"
                                            wire:confirm="Delete {{ $image['reference'] }} from this server? It has to be pulled or built again to be used."><x-reicon
                                                name="trash" class="size-3.5" />
                                            Delete
                                        </x-forms.button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-application.settings-section>
        </div>
    </div>
</div>
