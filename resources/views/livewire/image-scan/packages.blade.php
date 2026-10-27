@php
    $checkbox = fn (bool $selected) => $selected
        ? 'flex size-4 shrink-0 items-center justify-center rounded-[5px] border border-coollabs bg-coollabs text-white dark:border-brand dark:bg-brand dark:text-white'
        : 'flex size-4 shrink-0 items-center justify-center rounded-[5px] border border-neutral-300 bg-white dark:border-white/[0.14] dark:bg-white/[0.045]';
    $groupLabel = 'px-2 pb-1 pt-2 text-[10px] font-medium uppercase tracking-wider text-neutral-400 dark:text-fg-faint';
@endphp

<div class="contents">
    @if ($scans->isNotEmpty())
        <x-application.settings-section id="packages-section" title="Packages and SBOM" collapsible flush>
            <x-slot:actions>
                <x-table.dropdown role="menu" panel-class="min-w-56! p-1!">
                    <x-slot:trigger>
                        <button type="button" class="button normal-case!" aria-haspopup="menu" :aria-expanded="open">
                            <x-reicon name="download" class="size-3.5" />
                            Download SBOM
                            <x-reicon name="chevron-down" class="size-3.5" />
                        </button>
                    </x-slot:trigger>
                    @foreach ($downloads as $download)
                        @if (count($downloads) > 1)
                            <span class="{{ $groupLabel }} block max-w-72 truncate normal-case tracking-normal" title="{{ $download['image'] }}">{{ $download['image'] }}</span>
                        @endif
                        <a class="listbox-option" role="menuitem" href="{{ $download['cyclonedx'] }}" download @click="close()">CycloneDX 1.6 JSON</a>
                        <a class="listbox-option" role="menuitem" href="{{ $download['spdx'] }}" download @click="close()">SPDX 2.3 JSON</a>
                    @endforeach
                </x-table.dropdown>
            </x-slot:actions>

            <x-table.toolbar class="border-b border-neutral-200 p-3 dark:border-white/[0.08]">
                <x-slot:search>
                    <x-table.search placeholder="Search packages" loading-target="search"
                        wire:model.live.debounce.300ms="search" />
                </x-slot:search>
                @if (count($typeOptions) > 0)
                    <x-table.filter :active-count="count($types)" reset-action="resetFilters">
                        <span class="{{ $groupLabel }}">Type</span>
                        @foreach ($typeOptions as $option)
                            @php $selected = in_array($option, $types, true); @endphp
                            <button type="button" class="listbox-option" role="option"
                                aria-selected="{{ $selected ? 'true' : 'false' }}"
                                wire:click="toggleType({{ \Illuminate\Support\Js::from($option) }})">
                                <span class="truncate">{{ $option }}</span>
                                <span class="{{ $checkbox($selected) }}">
                                    @if ($selected)
                                        <svg class="size-3" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                            <path d="m2.25 6.15 2.35 2.3 5.15-5" stroke="currentColor" stroke-width="1.8"
                                                stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    @endif
                                </span>
                            </button>
                        @endforeach
                    </x-table.filter>
                @endif
            </x-table.toolbar>

            @if ($truncated)
                <div class="border-b border-neutral-200 p-3 dark:border-white/[0.08]">
                    <x-callout type="info" title="Too many packages">Only the first 10,000 are shown.</x-callout>
                </div>
            @endif

            <div class="relative">
                <x-table.loading target="search,toggleType,resetFilters,previousPage,nextPage,perPage" />
                @if ($items->isEmpty())
                    <x-empty size="sm" :title="$total === 0 ? 'No packages found.' : 'No matching packages'"
                        :description="$total === 0 ? null : 'Try another search, or reset the filters.'" />
                @else
                    <div class="data-table">
                        <div @class(['data-table-header image-scan-sbom-grid', 'has-image' => $showsImage])>
                            <span>Package</span>
                            <span>Version</span>
                            <span class="image-scan-secondary">Type</span>
                            @if ($showsImage)
                                <span class="image-scan-secondary">Image</span>
                            @endif
                            <span class="image-scan-secondary">License</span>
                        </div>
                        @foreach ($items as $package)
                            <div wire:key="sbom-package-{{ $package['key'] }}"
                                @class(['data-table-row image-scan-sbom-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]', 'has-image' => $showsImage])>
                                <span class="min-w-0 truncate text-[12px] font-medium text-neutral-950 dark:text-fg"
                                    title="{{ $package['purl'] ?: $package['name'] }}">{{ $package['name'] }}</span>
                                <span class="min-w-0 truncate font-mono text-[11px] text-neutral-600 dark:text-fg-dim">{{ $package['version'] }}</span>
                                <span class="image-scan-secondary min-w-0 truncate text-[11px] text-neutral-600 dark:text-fg-dim">{{ $package['type'] }}</span>
                                @if ($showsImage)
                                    <span class="image-scan-secondary min-w-0 truncate text-[11px] text-neutral-500 dark:text-fg-faint" title="{{ $package['image'] }}">{{ $package['image'] }}</span>
                                @endif
                                <span class="image-scan-secondary min-w-0 truncate text-[11px] text-neutral-600 dark:text-fg-dim"
                                    title="{{ implode(', ', $package['licenses']) }}">{{ implode(', ', $package['licenses']) }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <x-table-pagination :from="$items->firstItem() ?? 0" :to="$items->lastItem() ?? 0" :total="$items->total()"
                :current-page="$items->currentPage()" :last-page="$items->lastPage()"
                wire-target="previousPage,nextPage,perPage" previous-action="previousPage('packagesPage')"
                next-action="nextPage('packagesPage')">
                <x-slot:pageSize>
                    <x-page-size-select model="perPage" livewire storage-key="coolify.page-size.image-scan-packages" />
                </x-slot:pageSize>
            </x-table-pagination>
        </x-application.settings-section>
    @endif
</div>
