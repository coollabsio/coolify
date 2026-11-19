<div class="flex h-full flex-col">
    <x-slot:title>
        {{ str($scope)->headline() }} Variables > Canvas | Coolify
    </x-slot>
    {{-- Page search items; the menu is in the main sidebar. --}}
    <x-shared-variables.sidebar />
    <div data-environment-canvas data-mode="variables" data-endpoint="{{ $endpoint }}"
        data-team="{{ $teamId }}" data-user="{{ auth()->id() }}"
        class="relative min-h-0 flex-1" wire:ignore>
        {{-- The canvas moves this group into its toolbar (page actions last, at the right end),
             which can be outside this component,
             so the button opens the modal below with a window event. --}}
        <div data-ec-views class="flex items-center gap-2">
            <x-canvas-view-toggle on-canvas :views="['list' => ['List view', 'unordered-list']]"
                :list-url="$listUrl" :canvas-url="url()->current()" />
            @if ($canAddVariables)
                <button type="button" x-data x-on:click="$dispatch('open-new-shared-variable')"
                    class="button button-highlighted whitespace-nowrap">
                    <x-reicon name="plus" class="size-3.5" />
                    Add variable
                </button>
            @endif
        </div>
    </div>
    @if ($canAddVariables)
        <x-modal-input title="New Shared Variable" x-on:open-new-shared-variable.window="modalOpen = true">
            <x-slot:content></x-slot:content>
            <livewire:project.shared.environment-variable.add :shared="true" />
        </x-modal-input>
    @endif
</div>
