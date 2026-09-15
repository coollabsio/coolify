<div wire:init="load">
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Images | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="docker-images" />

        <div class="application-settings-form w-full min-w-0">
            <x-application.settings-section id="server-docker-images-section" title="Docker images"
                helper="Images stored on this server. Images that no container uses are marked unused and can be deleted."
                flush>
                <x-slot:actions>
                    @if ($loaded && $server->isFunctional() && $this->unusedCount() > 0)
                        @can('update', $server)
                            <x-modal-confirmation title="Delete unused images?"
                                buttonTitle="Delete unused images" submitAction="deleteUnused"
                                :actions="[
                                    'Deletes all images that are not used by any container.',
                                    'Old application images are removed, so rollback to previous versions may stop working.',
                                ]" :confirmWithText="false" :confirmWithPassword="false"
                                step2ButtonText="Delete unused images" />
                        @endcan
                    @endif
                    <x-forms.button wire:click="load">
                        <x-reicon name="refresh" class="size-3.5" />
                        Refresh
                    </x-forms.button>
                </x-slot:actions>

                @if (! $server->isFunctional())
                    <div class="p-6">
                        <x-callout type="warning" title="Server is not functional">
                            Validate and connect this server before managing its Docker images.
                        </x-callout>
                    </div>
                @elseif (! $loaded)
                    <div class="p-6">
                        <x-loading text="Reading images from the Docker daemon" />
                    </div>
                @else
                    <div class="border-b border-neutral-200 p-3 dark:border-white/[0.08]">
                        <div class="relative w-full max-w-sm">
                            <x-reicon name="search"
                                class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                            <input wire:model.live.debounce.300ms="search" type="search"
                                placeholder="Search by repository, tag or image ID" aria-label="Search images"
                                class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-8! pl-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-accent! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint">
                        </div>
                    </div>

                    @if ($visibleImages->isEmpty())
                        <div class="p-6">
                            <x-empty size="sm"
                                :title="trim($search) !== '' ? 'No matching images' : 'No images found'"
                                :description="trim($search) !== ''
                                    ? 'Try another repository, tag or ID, or clear the search.'
                                    : 'Images pulled or built on this server will appear here.'"
                                icon-name="storages" />
                        </div>
                    @else
                        <div class="hidden items-center gap-3 border-b border-neutral-200 px-4 py-2 text-[11px] font-medium tracking-wide text-neutral-500 uppercase md:flex dark:border-white/[0.08] dark:text-fg-dim">
                            <span class="min-w-0 flex-1">Image</span>
                            <span class="min-w-0 flex-1">Used by</span>
                            <span class="hidden w-28 shrink-0 lg:block">Image ID</span>
                            <span class="hidden w-24 shrink-0 lg:block">Created</span>
                            <span class="w-20 shrink-0">Size</span>
                            <span class="w-20 shrink-0"></span>
                        </div>
                        @foreach ($visibleImages as $image)
                            <div wire:key="docker-image-{{ $image['reference'] }}"
                                class="flex flex-wrap items-center gap-3 border-b border-neutral-200 px-4 py-3 last:border-b-0 md:flex-nowrap dark:border-white/[0.08]">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-[13px] font-medium text-neutral-950 dark:text-fg"
                                        title="{{ $image['reference'] }}">
                                        {{ $image['repository'] }}
                                        <span class="font-normal text-neutral-500 dark:text-fg-dim">:{{ $image['tag'] }}</span>
                                    </p>
                                    @if ($image['dangling'])
                                        <x-status-badge status="Dangling" type="warning" class="mt-1" />
                                    @endif
                                </div>
                                <div class="min-w-0 flex-1">
                                    @if ($image['usedBy'] === [])
                                        <x-status-badge status="Unused" type="warning" />
                                    @else
                                        <span class="block truncate text-[11px] text-neutral-600 dark:text-fg-dim"
                                            title="{{ implode(', ', $image['usedBy']) }}">
                                            {{ implode(', ', $image['usedBy']) }}
                                        </span>
                                    @endif
                                </div>
                                <div class="hidden w-28 shrink-0 items-center gap-1 lg:flex">
                                    <span class="truncate font-mono text-[11px] text-neutral-600 dark:text-fg-dim">
                                        {{ $image['shortId'] }}
                                    </span>
                                    <x-copy-button :value="$image['id']" label="Copy image ID" />
                                </div>
                                <div class="hidden w-24 shrink-0 truncate text-[11px] text-neutral-600 lg:block dark:text-fg-dim">
                                    {{ $image['created'] }}
                                </div>
                                <div class="w-20 shrink-0 truncate text-[11px] text-neutral-600 dark:text-fg-dim">
                                    {{ $image['size'] }}
                                </div>
                                <div class="w-20 shrink-0 text-right">
                                    @if ($image['usedBy'] === [])
                                        <x-forms.button canGate="update" :canResource="$server" isError
                                            wire:click="delete('{{ $image['reference'] }}')"
                                            wire:confirm="Delete {{ $image['reference'] }} from this server? It has to be pulled or built again before it can be used.">
                                            Delete
                                        </x-forms.button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    @endif
                @endif
            </x-application.settings-section>
        </div>
    </div>
</div>
