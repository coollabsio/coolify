<div>
    @if ($isHeaderVisible)
        <div>
            <div class="flex items-center gap-2">
                <h2>Storages</h2>
                @if ($resource->type() !== 'service')
                    <x-helper
                        helper="For Preview Deployments, storage has a <span class='text-helper'>-pr-#PRNumber</span> in their
            volume
            name, example: <span class='text-helper'>-pr-1</span>" />
                    <x-forms.button class="btn" onclick="newStorage.showModal()">+ Add</x-forms.button>
                    <livewire:project.shared.storages.add :uuid="$resource->uuid" />
                @endif
            </div>
            <div class="pb-4">Persistent storage to preserve data between deployments.</div>
            @if ($resource->type() === 'service')
                <span class="text-warning">Please modify storage layout in your <a class="underline"
                        href="{{ Str::of(url()->current())->beforeLast('/') }}">Docker Compose</a> file.</span>
                <h2 class="pt-4">{{ Str::headline($resource->name) }} </h2>
            @endif
        </div>
    @endif
    <div class="flex flex-col gap-4">
        @foreach ($resource->persistentStorages as $storage)
            @if ($resource->type() === 'service')
                <livewire:project.shared.storages.show wire:key="storage-{{ $storage->id }}" :storage="$storage" :isFirst="$loop->first"
                    isReadOnly='true' />
            @else
                <livewire:project.shared.storages.show wire:key="storage-{{ $storage->id }}" :storage="$storage" />
            @endif
        @endforeach
    </div>
</div>
