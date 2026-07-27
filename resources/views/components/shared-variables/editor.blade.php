@props([
    'resource',
    'variables',
    'type',
    'title',
    'view',
    'variablesLabel',
])

@php
    $alphabeticalPositions = $variables->sortBy('key')->values()->pluck('id')->flip();
    $sharedVariableRows = $variables
        ->map(fn($variable) => [
            'key' => mb_strtolower($variable->key),
            'comment' => mb_strtolower($variable->comment ?? ''),
            'scope' => mb_strtolower(str($type)->headline()),
        ])
        ->values();
@endphp

<div class="application-settings-form w-full" x-data="{
    sharedSearch: '',
    sharedSort: 'alphabetical',
    sortOpen: false,
    rows: @js($sharedVariableRows),
    get filteredCount() {
        const query = this.sharedSearch.trim().toLowerCase();
        if (!query) return this.rows.length;
        return this.rows.filter(row => [row.key, row.comment, row.scope].some(value => value.includes(query))).length;
    }
}">
    <x-dashboard.navbar section="shared-variables" />

    <x-application.settings-section :title="$title" flush>
        <x-slot:actions>
            <button type="button" class="button" wire:click="switch">
                <x-reicon :name="$view === 'normal' ? 'browser-code' : 'unordered-list'" class="size-3.5" />
                {{ $view === 'normal' ? 'Developer view' : 'Normal view' }}
            </button>
        </x-slot:actions>

        @if ($view === 'normal')
            <div
                class="flex flex-col gap-3 border-b border-neutral-200 p-3 sm:flex-row sm:items-center sm:justify-between dark:border-white/[0.08]">
                <div class="relative w-full sm:max-w-sm">
                    <x-reicon name="search"
                        class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                    <input x-model.debounce.150ms="sharedSearch" type="search" placeholder="Search variables"
                        class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-8! pl-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-neutral-300! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint">
                    <button x-cloak x-show="sharedSearch" @click="sharedSearch = ''" type="button"
                        class="absolute top-1/2 right-2 flex size-5 -translate-y-1/2 items-center justify-center rounded text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.07] dark:hover:text-fg"
                        aria-label="Clear search">
                        <x-reicon name="x" class="size-3" />
                    </button>
                </div>

                <div class="flex items-center gap-2">
                    <div class="relative" @click.outside="sortOpen = false" @keydown.escape.window="sortOpen = false">
                        <button type="button" class="button" @click="sortOpen = !sortOpen">
                            <x-reicon name="sort-direction" class="size-3.5" />
                            Sort
                        </button>
                        <div x-show="sortOpen" x-cloak x-transition.opacity.duration.120ms
                            class="listbox-panel right-0 left-auto! min-w-48">
                            <button type="button" class="listbox-option"
                                @click="sharedSort = 'alphabetical'; sortOpen = false">
                                <span>Alphabetical</span>
                                <svg x-show="sharedSort === 'alphabetical'" class="size-3.5 shrink-0" viewBox="0 0 24 24"
                                    fill="none">
                                    <path d="M5 12l5 5 9-11" stroke="currentColor" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <button type="button" class="listbox-option"
                                @click="sharedSort = 'creation'; sortOpen = false">
                                <span>Creation order</span>
                                <svg x-show="sharedSort === 'creation'" class="size-3.5 shrink-0" viewBox="0 0 24 24"
                                    fill="none">
                                    <path d="M5 12l5 5 9-11" stroke="currentColor" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    @can('update', $resource)
                        <x-modal-input title="New Shared Variable">
                            <x-slot:content>
                                <button type="button"
                                    class="button bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!">
                                    <x-reicon name="plus" class="size-3.5" />
                                    Add variable
                                </button>
                            </x-slot:content>
                            <livewire:project.shared.environment-variable.add :shared="true" />
                        </x-modal-input>
                    @endcan
                </div>
            </div>

            @if ($variables->isEmpty())
                <x-empty title="No shared variables"
                    description="Add a variable to make it available to resources in this scope." size="sm">
                    <x-slot:icon>
                        <x-reicon name="variables" class="size-6" />
                    </x-slot:icon>
                </x-empty>
            @else
                <div class="data-table flex w-full flex-col">
                    <div class="data-table-header env-table-grid order-[-1]">
                        <span>Name</span>
                        <span>Scope</span>
                        <span>Comment</span>
                        <span class="text-center">Literal</span>
                        <span class="text-center">Multiline</span>
                        <span class="text-center">Buildtime</span>
                        <span class="text-center">Runtime</span>
                        <span></span>
                    </div>
                    @foreach ($variables as $env)
                        <livewire:project.shared.environment-variable.show
                            wire:key="shared-variable-{{ $type }}-{{ $env->id }}" :env="$env"
                            :type="$type" :tableAlphabeticalOrder="$alphabeticalPositions[$env->id]"
                            :tableCreationOrder="$loop->index" />
                    @endforeach
                    <div
                        class="order-[9999] flex min-h-11 items-center border-t border-neutral-200 px-4 text-[11px] text-neutral-500 dark:border-white/[0.08] dark:text-fg-faint">
                        <span x-text="`${filteredCount} ${filteredCount === 1 ? 'variable' : 'variables'}`"></span>
                    </div>
                </div>
            @endif
        @else
            <form wire:submit="submit" class="p-4">
                <x-unsaved-bar action="submit" />
                <x-forms.textarea canGate="update" :canResource="$resource" rows="20"
                    class="whitespace-pre-wrap" id="variables" wire:model="variables" monospace
                    :label="$variablesLabel" />
            </form>
        @endif
    </x-application.settings-section>
</div>
