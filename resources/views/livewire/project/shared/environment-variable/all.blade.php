@php
    $showEnvironmentType = $showPreview;
    $activeFilterCount = count($variableFilters) + count($serviceFilters) + ($environmentFilter !== 'all' ? 1 : 0);
    $filterLabels = [
        'managed' => 'Managed', 'user' => 'User-defined', 'buildtime' => 'Buildtime',
        'runtime' => 'Runtime', 'multiline' => 'Multiline', 'literal' => 'Literal',
    ];
    $activeFilterLabels = collect($variableFilters)->map(fn ($filter) => $filterLabels[$filter] ?? $filter);
    $activeFilterLabels = $activeFilterLabels->merge($serviceFilters);
    if ($environmentFilter !== 'all') {
        $activeFilterLabels->push(str($environmentFilter)->headline()->toString());
    }
    $activeFilterText = $activeFilterLabels->implode(', ');
@endphp
<div class="flex flex-col gap-4" wire:init="loadEnvironmentVariables">
    <x-application.settings-section id="environment-variables-section" title="Environment variables"
        helper="Environment variables (secrets) for this resource.">
        @can('manageEnvironment', $resource)
            <x-slot:actions>
                <x-forms.button wire:click='switch' wire:loading.attr="disabled"
                    wire:target="loadEnvironmentVariables,switch">
                    {{ $view === 'normal' ? 'Developer view' : 'Normal view' }}
                </x-forms.button>
            </x-slot:actions>
        @endcan
        @if ($view === 'normal')
            @if ($resourceClass === 'App\Models\Application')
                <div class="grid w-full items-end gap-4 sm:grid-cols-2">
                    @if (data_get($resource, 'build_pack') !== 'dockercompose')
                        <x-forms.listbox id="is_env_sorting_enabled" label="Environment variable order"
                            onChange="instantSave"
                            helper="Controls how environment variables are ordered in the list and written to the generated .env file on deploy."
                            :options="[
                                ['value' => false, 'label' => 'Creation order'],
                                ['value' => true, 'label' => 'Alphabetical'],
                            ]" :disabled="! auth()->user()->can('manageEnvironment', $resource)" />
                    @endif
                    <x-forms.listbox id="use_build_secrets" label="Build secrets" onChange="instantSave"
                        helper="Docker BuildKit secrets keep values out of the final image for enhanced security during builds. Requires Docker 18.09+ with BuildKit support."
                        :options="[
                            ['value' => false, 'label' => 'Standard build arguments'],
                            ['value' => true, 'label' => 'Docker BuildKit secrets'],
                        ]" :disabled="! auth()->user()->can('manageEnvironment', $resource)" />
                </div>
            @else
                <p class="text-sm text-neutral-500 dark:text-fg-dim">Manage this resource's environment variables below.</p>
            @endif
        @else
            <form wire:submit.prevent='submit' class="flex w-full flex-col gap-4">
                @can('manageEnvironment', $resource)
                    <x-callout type="info" title="Note">
                        Inline comments with space before # (e.g., <code class="font-mono">KEY=value #comment</code>) are stripped.
                    </x-callout>
                    <x-forms.textarea rows="10" class="whitespace-pre-wrap font-sans" id="variables"
                        wire:model="variables" label="Production"></x-forms.textarea>
                    @if ($showPreview)
                        <x-forms.textarea rows="10" class="whitespace-pre-wrap font-sans" label="Preview deployments"
                            id="variablesPreview" wire:model="variablesPreview"></x-forms.textarea>
                    @endif
                    <x-unsaved-bar action="submit" />
                @else
                    <x-forms.textarea rows="10" class="whitespace-pre-wrap font-sans" id="variables"
                        wire:model="variables" label="Production" disabled></x-forms.textarea>
                    @if ($showPreview)
                        <x-forms.textarea rows="10" class="whitespace-pre-wrap font-sans" label="Preview deployments"
                            id="variablesPreview" wire:model="variablesPreview" disabled></x-forms.textarea>
                    @endif
                @endcan
            </form>
        @endif
    </x-application.settings-section>

    {{-- Toolbar: search left; filter and add right --}}
    @if ($view === 'normal')
        <x-table.toolbar class="mt-2" aria-busy="{{ ! $readyToLoad ? 'true' : 'false' }}">
            <x-slot:search>
                <x-table.search placeholder="Search environment variables"
                    loading-target="search,loadEnvironmentVariables" wire:model.live.debounce.300ms="search"
                    :disabled="! $readyToLoad" />
            </x-slot:search>
            <x-table.filter :active-count="$activeFilterCount" :active-text="$activeFilterText"
                reset-action="clearFilters">
                        @foreach ([
                            'managed' => 'Managed',
                            'user' => 'User-defined',
                            'buildtime' => 'Buildtime',
                            'runtime' => 'Runtime',
                            'multiline' => 'Multiline',
                            'literal' => 'Literal',
                        ] as $value => $label)
                            <button type="button" class="listbox-option" wire:click="toggleVariableFilter('{{ $value }}')">
                                <span>{{ $label }}</span>
                                @php
                                    $selected = in_array($value, $variableFilters, true);
                                @endphp
                                <span @class([
                                    'flex size-4 shrink-0 items-center justify-center rounded-[5px] border',
                                    'border-coollabs bg-coollabs text-white dark:border-warning dark:bg-warning dark:text-black' => $selected,
                                    'border-neutral-300 bg-white dark:border-white/[0.14] dark:bg-white/[0.045]' => ! $selected,
                                ])>
                                    @if ($selected)
                                        <svg class="size-3" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                            <path d="m2.25 6.15 2.35 2.3 5.15-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    @endif
                                </span>
                            </button>
                        @endforeach
                        @if ($showPreview)
                            <div class="my-1 border-t border-neutral-200 dark:border-white/10"></div>
                            @foreach (['all' => 'All environments', 'production' => 'Production', 'preview' => 'Preview'] as $value => $label)
                                <button type="button" class="listbox-option" wire:click="setEnvironmentFilter('{{ $value }}')" @click="open = false">
                                    <span>{{ $label }}</span>
                                    <span @class([
                                        'flex size-4 shrink-0 items-center justify-center rounded-[5px] border',
                                        'border-coollabs bg-coollabs text-white dark:border-warning dark:bg-warning dark:text-black' => $environmentFilter === $value,
                                        'border-neutral-300 bg-white dark:border-white/[0.14] dark:bg-white/[0.045]' => $environmentFilter !== $value,
                                    ])>
                                        @if ($environmentFilter === $value)
                                            <svg class="size-3" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                                <path d="m2.25 6.15 2.35 2.3 5.15-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                        @endif
                                    </span>
                                </button>
                            @endforeach
                        @endif
                        @if ($this->serviceFilterOptions !== [])
                            <div class="my-1 border-t border-neutral-200 dark:border-white/10"></div>
                            <div class="px-3 py-1 text-[11px] font-medium uppercase tracking-wide text-neutral-400 dark:text-fg-faint">Services</div>
                            @foreach ($this->serviceFilterOptions as $serviceName)
                                <button type="button" class="listbox-option" wire:click="toggleServiceFilter(@js($serviceName))">
                                    <span class="truncate">{{ $serviceName }}</span>
                                    @php
                                        $serviceSelected = in_array($serviceName, $serviceFilters, true);
                                    @endphp
                                    <span @class([
                                        'flex size-4 shrink-0 items-center justify-center rounded-[5px] border',
                                        'border-coollabs bg-coollabs text-white dark:border-warning dark:bg-warning dark:text-black' => $serviceSelected,
                                        'border-neutral-300 bg-white dark:border-white/[0.14] dark:bg-white/[0.045]' => ! $serviceSelected,
                                    ])>
                                        @if ($serviceSelected)
                                            <svg class="size-3" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                                <path d="m2.25 6.15 2.35 2.3 5.15-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                        @endif
                                    </span>
                                </button>
                            @endforeach
                        @endif
            </x-table.filter>
            <x-table.sort>
                        @foreach (['default' => 'Default order', 'name_asc' => 'Name A–Z', 'name_desc' => 'Name Z–A'] as $value => $label)
                            <button type="button" class="listbox-option" wire:click="setTableSort('{{ $value }}')" @click="open = false">
                                <span>{{ $label }}</span>
                                @if ($tableSort === $value)<span>✓</span>@endif
                            </button>
                        @endforeach
            </x-table.sort>
                @can('manageEnvironment', $resource)
                    {{-- Do not disable Add based on readyToLoad: modal-input uses wire:ignore, so a
                         disabled attribute painted on first load would never re-enable. --}}
                    <x-modal-input title="New Environment Variable" :closeOutside="false">
                        <x-slot:content>
                            <button type="button"
                                class="button button-highlighted">
                                <x-reicon name="plus" class="size-3.5" />
                                Add
                            </button>
                        </x-slot:content>
                        <livewire:project.shared.environment-variable.add />
                    </x-modal-input>
                @endcan
        </x-table.toolbar>
    @endif

    @if ($view === 'normal')
        @if (! $readyToLoad)
            <div id="environment-table-section"
                class="application-settings-section-body mt-1 flex min-h-40 w-full scroll-mt-28 items-center justify-center"
                wire:loading.class="opacity-100" wire:target="loadEnvironmentVariables">
                <x-loading text="Loading environment variables..." />
            </div>
        @else
            @php
                $totalRows = $this->environmentVariableRowCount;
                $currentPage = $this->currentEnvironmentVariablePage;
                $lastPage = $this->environmentVariableLastPage;
                $firstVisibleRow = $totalRows === 0 ? 0 : ($currentPage - 1) * $perPage + 1;
                $lastVisibleRow = min($currentPage * $perPage, $totalRows);
            @endphp
            <div id="environment-table-section"
                class="application-settings-section-body relative mt-1 scroll-mt-28 {{ $totalRows > 0 ? 'is-flush' : '' }} w-full">
                @if ($this->isSearchActive && $totalRows === 0)
                    <x-empty size="sm" title="No environment variables found"
                        description="No variables match your search." />
                @elseif ($totalRows > 0)
                    <div class="data-table w-full">
                        <div class="environment-table-scroll relative">
                            <div class="transition-all"
                                wire:loading.class="pointer-events-none opacity-40 blur-[2px]"
                                wire:target="toggleVariableFilter,toggleServiceFilter,clearFilters,setEnvironmentFilter,setTableSort,setEnvironmentVariablePage,previousEnvironmentVariablePage,nextEnvironmentVariablePage">
                            <div class="data-table-header env-table-grid {{ $showEnvironmentType ? '' : 'env-table-grid-no-type' }}">
                            <span>Name</span>
                            <span class="text-center">Managed</span>
                            @if ($showEnvironmentType)
                                <span>Type</span>
                            @endif
                            <span class="text-center">Literal</span>
                            <span class="text-center">Multiline</span>
                            <span class="text-center">Buildtime</span>
                            <span class="text-center">Runtime</span>
                            <span></span>
                        </div>
                            @foreach ($this->environmentVariablePageRows as $row)
                            @if ($row['kind'] === 'managed')
                                <livewire:project.shared.environment-variable.show wire:key="{{ $row['id'] }}"
                                    :env="$row['environmentVariable']" :type="$resource->type()" :showEnvironmentType="$showEnvironmentType" />
                            @else
                                <livewire:project.shared.environment-variable.show-hardcoded
                                    wire:key="{{ $row['id'] }}" :env="$row['environmentVariable']"
                                    :isPreview="$row['scope'] === 'preview'" :showEnvironmentType="$showEnvironmentType" />
                            @endif
                            @endforeach
                            </div>
                            <x-table.loading
                                target="toggleVariableFilter,toggleServiceFilter,clearFilters,setEnvironmentFilter,setTableSort,setEnvironmentVariablePage,previousEnvironmentVariablePage,nextEnvironmentVariablePage"
                                text="Loading environment variables..." />
                        </div>
                        <x-table-pagination :from="$firstVisibleRow" :to="$lastVisibleRow" :total="$totalRows"
                            :current-page="$currentPage" :last-page="$lastPage"
                            wire-target="setEnvironmentVariablePage,previousEnvironmentVariablePage,nextEnvironmentVariablePage"
                            first-action="setEnvironmentVariablePage(1)"
                            previous-action="previousEnvironmentVariablePage"
                            next-action="nextEnvironmentVariablePage"
                            last-action="setEnvironmentVariablePage({{ $lastPage }})">
                            <x-slot:pageSize>
                                <x-page-size-select model="perPage" livewire storage-key="coolify.page-size.environment-variables" />
                            </x-slot:pageSize>
                        </x-table-pagination>
                    </div>
                @else
                    <div class="relative min-h-40">
                        <div wire:loading.class="pointer-events-none opacity-40 blur-[2px]" wire:target="clearFilters">
                            <x-empty size="sm" title="No environment variables"
                                description="Add your first variable with the + Add button above."
                                icon-name="variables" />
                        </div>
                        <x-table.loading target="clearFilters" text="Loading environment variables..." />
                    </div>
                @endif
            </div>
        @endif
    @endif
</div>
