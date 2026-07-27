<div class="flex flex-col gap-4">
    <x-application.settings-section id="environment-variables-section" title="Environment variables"
        helper="Environment variables (secrets) for this resource.">
        @can('manageEnvironment', $resource)
            <x-slot:actions>
                <x-forms.button wire:click='switch'>
                    {{ $view === 'normal' ? 'Developer view' : 'Normal view' }}
                </x-forms.button>
            </x-slot:actions>
        @endcan
        @if ($view === 'normal')
            @if ($resourceClass === 'App\Models\Application')
                <div class="grid w-full gap-4 sm:grid-cols-2">
                    <x-forms.listbox id="use_build_secrets" label="Build secrets" onChange="instantSave"
                        helper="Docker BuildKit secrets keep values out of the final image for enhanced security during builds. Requires Docker 18.09+ with BuildKit support."
                        :options="[
                            ['value' => false, 'label' => 'Standard build arguments'],
                            ['value' => true, 'label' => 'Docker BuildKit secrets'],
                        ]" x-bind:disabled="@js(!auth()->user()->can('manageEnvironment', $resource))" />
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

    {{-- Toolbar: search left; filter, sorting and add right --}}
    @if ($view === 'normal')
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <div class="relative min-w-0 max-w-md flex-1">
                <input type="search" placeholder="Search environment variables"
                    aria-label="Search environment variables" wire:model.live.debounce.300ms="search"
                    class="input w-full pl-8!" />
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2.5">
                    <x-reicon name="search" wire:loading.remove wire:target="search"
                        class="size-3.5 text-neutral-400 dark:text-fg-faint" />
                    <svg wire:loading wire:target="search" aria-hidden="true"
                        class="size-3.5 animate-spin text-neutral-400 dark:text-fg-dim" fill="none"
                        viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor"
                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                    </svg>
                </div>
            </div>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                @if ($resource->type() === 'application' && $showPreview)
                    <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false">
                        <button type="button" class="button" @click="open = !open" @click.outside="open = false"
                            aria-haspopup="listbox" :aria-expanded="open">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8"
                                stroke="currentColor" class="size-3.5">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 0 1-.659 1.591l-5.432 5.432a2.25 2.25 0 0 0-.659 1.591v2.927a2.25 2.25 0 0 1-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 0 0-.659-1.591L3.659 7.409A2.25 2.25 0 0 1 3 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0 1 12 3Z" />
                            </svg>
                            Filter
                        </button>
                        <div class="listbox-panel left-auto! right-0" x-show="open" x-cloak role="listbox">
                            @foreach ([
                                'all' => 'All environments',
                                'production' => 'Production',
                                'preview' => 'Preview',
                            ] as $filterValue => $filterLabel)
                                <button type="button" class="listbox-option" role="option"
                                    aria-selected="{{ $environmentFilter === $filterValue ? 'true' : 'false' }}"
                                    wire:click="setEnvironmentFilter('{{ $filterValue }}')" @click="open = false">
                                    <span class="truncate">{{ $filterLabel }}</span>
                                    @if ($environmentFilter === $filterValue)
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                            stroke-width="2.5" stroke="currentColor" class="size-3.5 shrink-0">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="m4.5 12.75 6 6 9-13.5" />
                                        </svg>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif
                @if ($resourceClass === 'App\Models\Application' && data_get($resource, 'build_pack') !== 'dockercompose')
                    @can('manageEnvironment', $resource)
                        <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false">
                            <button type="button" class="button" @click="open = !open" @click.outside="open = false"
                                aria-haspopup="listbox" :aria-expanded="open">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                    stroke-width="1.8" stroke="currentColor" class="size-3.5">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M3 7.5 7.5 3m0 0L12 7.5M7.5 3v13.5m13.5 0L16.5 21m0 0L12 16.5m4.5 4.5V7.5" />
                                </svg>
                                Sort
                            </button>
                            <div class="listbox-panel left-auto! right-0" x-show="open" x-cloak role="listbox">
                                @foreach ([
                                    1 => 'Alphabetical',
                                    0 => 'Creation order',
                                ] as $sortValue => $sortLabel)
                                    <button type="button" class="listbox-option" role="option"
                                        @click="open = false; $wire.is_env_sorting_enabled = {{ $sortValue ? 'true' : 'false' }}; $wire.instantSave()">
                                        <span class="truncate">{{ $sortLabel }}</span>
                                        @if ($is_env_sorting_enabled === (bool) $sortValue)
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                                stroke-width="2.5" stroke="currentColor" class="size-3.5 shrink-0">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="m4.5 12.75 6 6 9-13.5" />
                                            </svg>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endcan
                @endif
                @can('manageEnvironment', $resource)
                    <x-modal-input title="New Environment Variable" :closeOutside="false">
                        <x-slot:content>
                            <button type="button"
                                class="button bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!">
                                <x-reicon name="plus" class="size-3.5" />
                                Add
                            </button>
                        </x-slot:content>
                        <livewire:project.shared.environment-variable.add />
                    </x-modal-input>
                @endcan
            </div>
        </div>
    @endif

    @if ($view === 'normal')
        @php
            $totalRows = $this->environmentVariableRowCount;
            $currentPage = $this->currentEnvironmentVariablePage;
            $lastPage = $this->environmentVariableLastPage;
            $firstVisibleRow = $totalRows === 0 ? 0 : ($currentPage - 1) * $perPage + 1;
            $lastVisibleRow = min($currentPage * $perPage, $totalRows);
        @endphp
        <div id="environment-table-section"
            class="application-settings-section-body mt-1 scroll-mt-28 {{ $totalRows > 0 ? 'is-flush' : '' }} w-full">
            @if ($this->isSearchActive && $totalRows === 0)
                <x-empty size="sm" title="No environment variables found"
                    description="No variables match your search." />
            @elseif ($totalRows > 0)
                <div class="data-table w-full">
                    <div class="data-table-header env-table-grid">
                        <span>Name</span>
                        <span>Type</span>
                        <span>Comment</span>
                        <span class="text-center">Literal</span>
                        <span class="text-center">Multiline</span>
                        <span class="text-center">Buildtime</span>
                        <span class="text-center">Runtime</span>
                        <span></span>
                    </div>
                    @foreach ($this->environmentVariablePageRows as $row)
                        @if ($row['kind'] === 'managed')
                            <livewire:project.shared.environment-variable.show wire:key="{{ $row['id'] }}"
                                :env="$row['environmentVariable']" :type="$resource->type()" />
                        @else
                            <livewire:project.shared.environment-variable.show-hardcoded
                                wire:key="{{ $row['id'] }}" :env="$row['environmentVariable']"
                                :isPreview="$row['scope'] === 'preview'" />
                        @endif
                    @endforeach
                    <div class="flex items-center justify-between gap-3 px-4 py-3">
                        <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                            Showing <span
                                class="tabular-nums text-black dark:text-fg">{{ $firstVisibleRow }}–{{ $lastVisibleRow }}</span>
                            of <span class="tabular-nums text-black dark:text-fg">{{ $totalRows }}</span>
                        </p>
                        <div
                            class="inline-flex h-8 overflow-hidden rounded-lg border border-neutral-200 dark:border-white/[0.10]">
                            <button type="button"
                                class="flex w-10 items-center justify-center border-r border-neutral-200 text-neutral-500 transition-colors hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-35 dark:border-white/[0.10] dark:text-fg-dim dark:hover:bg-white/[0.06]"
                                aria-label="First page" title="First page"
                                wire:click="setEnvironmentVariablePage(1)" @disabled($currentPage === 1)>
                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <path d="M18 6L12 12L18 18M11 6L5 12L11 18" stroke="currentColor"
                                        stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <button type="button"
                                class="flex w-10 items-center justify-center border-r border-neutral-200 text-neutral-500 transition-colors hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-35 dark:border-white/[0.10] dark:text-fg-dim dark:hover:bg-white/[0.06]"
                                aria-label="Previous page" title="Previous page"
                                wire:click="previousEnvironmentVariablePage" @disabled($currentPage === 1)>
                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <path d="M15 6L9 12L15 18" stroke="currentColor" stroke-width="1.8"
                                        stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <span
                                class="flex min-w-12 items-center justify-center border-r border-neutral-200 px-3 text-[13px] tabular-nums text-black dark:border-white/[0.10] dark:text-fg">
                                {{ $currentPage }}
                            </span>
                            <button type="button"
                                class="flex w-10 items-center justify-center border-r border-neutral-200 text-neutral-500 transition-colors hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-35 dark:border-white/[0.10] dark:text-fg-dim dark:hover:bg-white/[0.06]"
                                aria-label="Next page" title="Next page" wire:click="nextEnvironmentVariablePage"
                                @disabled($currentPage === $lastPage)>
                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <path d="M9 6L15 12L9 18" stroke="currentColor" stroke-width="1.8"
                                        stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <button type="button"
                                class="flex w-10 items-center justify-center text-neutral-500 transition-colors hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-35 dark:text-fg-dim dark:hover:bg-white/[0.06]"
                                aria-label="Last page" title="Last page"
                                wire:click="setEnvironmentVariablePage({{ $lastPage }})"
                                @disabled($currentPage === $lastPage)>
                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <path d="M6 6L12 12L6 18M13 6L19 12L13 18" stroke="currentColor"
                                        stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            @else
                <x-empty size="sm" title="No environment variables"
                    description="Add your first variable with the + Add button above.">
                    <x-slot:icon>
                        <x-reicon name="variables" class="size-8" />
                    </x-slot:icon>
                </x-empty>
            @endif
        </div>
    @endif
</div>
