<div class="space-y-4">
    <x-images.navbar />
    <div class="flex items-center gap-2">
        <h2>Registries</h2>
        <x-modal-input buttonTitle="+ Add" title="New Registry">
            <livewire:images.registry.create />
        </x-modal-input>
    </div>
    <div>Configure registries to pull Docker images from.</div>

    @forelse($registries as $registry)
        <livewire:images.registry.show :registry="$registry" wire:key="registry-{{ $registry->id }}" />
    @empty
        <div class="text-center py-8 text-gray-500">
            No registries configured yet. Add one to get started.
        </div>
    @endforelse
</div>
