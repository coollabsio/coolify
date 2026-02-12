<div>
    <x-slot:title>
        Storages | Coolify
    </x-slot>
    <div class="form-section-title mb-6">
        <h1>S3 Storages</h1>
        <div class="flex items-center gap-2">
            @can('create', App\Models\S3Storage::class)
                <x-modal-input buttonTitle="+ Add" title="New S3 Storage" :closeOutside="false">
                    <livewire:storage.create />
                </x-modal-input>
            @endcan
        </div>
    </div>
    <p class="text-sm text-neutral-500 dark:text-neutral-400 -mt-4 mb-4">S3 storages for backups.</p>
    <div class="grid gap-4 lg:grid-cols-2 -mt-1">
        @forelse ($s3 as $storage)
            <a {{ wireNavigate() }} href="/storages/{{ $storage->uuid }}" @class(['gap-2 border cursor-pointer coolbox group'])>
                <div class="flex flex-col justify-center mx-6">
                    <div class="box-title">
                        {{ $storage->name }}
                    </div>
                    <div class="box-description">
                        {{ $storage->description }}
                    </div>
                    @if (!$storage->is_usable)
                        <span
                            class="px-2 py-1 text-xs font-semibold text-red-800 bg-red-100 rounded dark:text-red-100 dark:bg-red-800">
                            Not Usable
                        </span>
                    @endif
                </div>
            </a>
        @empty
            <div class="empty-state">No storage found.</div>
        @endforelse
    </div>
</div>
