<div>
    <div>
        <div class="flex items-center gap-2">
            <h2>Storages</h2>
            <x-helper
                helper="For Preview Deployments, storage has a <span class='text-helper'>-pr-#PRNumber</span> in their
            volume
            name, example: <span class='text-helper'>-pr-1</span>"/>
            <x-forms.button class="btn" onclick="newStorage.showModal()">+ Add</x-forms.button>
            <livewire:project.shared.storages.add/>
        </div>
        <div>Persistent storage to preserve data between deployments.</div>
    </div>
    <div class="flex flex-col gap-2 py-4">
        @forelse ($resource->persistentStorages as $storage)
            <livewire:project.shared.storages.show wire:key="storage-{{ $storage->id }}" :storage="$storage"/>
        @empty
            <div class="text-neutral-500">No storages found.</div>
        @endforelse
    </div>
</div>
