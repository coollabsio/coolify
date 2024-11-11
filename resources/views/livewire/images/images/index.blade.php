<div>
    <x-images.navbar />
    <div class="flex gap-2">
        <h2 class="pb-4">Images</h2>
        <x-modal-input buttonTitle="+ Add" disabled title="New Image">
            <livewire:security.private-key.create />
        </x-modal-input>

    </div>
    {{-- Images Tab Content --}}
    <div x-show="$wire.activeTab === 'images'" x-cloak>
        <div class="text-gray-500">
            Image management coming soon...
        </div>
    </div>
</div>
