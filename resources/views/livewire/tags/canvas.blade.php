<div class="h-full">
    <x-slot:title>
        Tags > Canvas | Coolify
    </x-slot>
    <div data-environment-canvas data-mode="tags" data-endpoint="/tags/canvas"
        data-team="{{ $teamId }}" data-user="{{ auth()->id() }}"
        class="relative h-full" wire:ignore>
        {{-- The canvas moves this group into its toolbar (page actions last, at the right end),
             which can be outside this component,
             so the button opens the modal below with a window event. --}}
        <div data-ec-views class="flex items-center gap-2">
            <x-canvas-view-toggle on-canvas :list-url="route('tags.show')"
                :canvas-url="route('tags.canvas')" />
            @can('create', App\Models\Tag::class)
                <button type="button" x-data x-on:click="$dispatch('open-new-tag')"
                    class="button button-highlighted whitespace-nowrap">
                    <x-reicon name="plus" class="size-3.5" />
                    New tag
                </button>
            @endcan
        </div>
    </div>
    @can('create', App\Models\Tag::class)
        <x-modal-input title="New tag" x-on:open-new-tag.window="modalOpen = true">
            <x-slot:content></x-slot:content>
            @include('livewire.tags.partials.new-tag-form')
        </x-modal-input>
    @endcan
</div>
