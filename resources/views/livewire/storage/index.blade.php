<div>
    <x-slot:title>
        Storages | Coolify
    </x-slot>
    <div class="flex items-center gap-2">
        <h1>S3 Storages</h1>
        <x-modal-input buttonTitle="+ Add" title="New S3 Storage" :closeOutside="false">
            <livewire:storage.create />
        </x-modal-input>
    </div>
    <div class="subtitle">S3 storages for backups.</div>
    <div class="grid gap-2 lg:grid-cols-2">
        @forelse ($s3 as $storage)
            <a href="/storages/{{ $storage->uuid }}" wire:navigate @class(['gap-2 border cursor-pointer box group border-transparent'])>
                <div class="flex flex-col mx-6">
                    <div class="box-title">
                        {{ $storage->name }}
                    </div>
                    <div class="box-description">
                        {{ $storage->description }}
                    </div>
                    @if (!$storage->is_usable)
                        <div class="text-red-500">Not Usable</div>
                    @endif
                </div>
            </a>
        @empty
            <div>
                <div>No storage found.</div>
            </div>
        @endforelse
    </div>
</div>
