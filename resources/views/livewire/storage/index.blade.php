<div>
    <x-slot:title>
        Storages | Coolify
    </x-slot>
    <div class="flex items-start gap-2">
        <h1>S3 Storages</h1>
        <x-modal-input buttonTitle="+ Add" title="New S3 Storage" :closeOutside="false">
            <livewire:storage.create />
        </x-modal-input>
    </div>
    <div class="subtitle">S3 storages for backups.</div>
    <div class="grid gap-2 lg:grid-cols-2">
        @forelse ($s3 as $storage)
            <div x-data x-on:click="goto('{{ $storage->uuid }}')" @class(['gap-2 border cursor-pointer box group border-transparent'])>
                <div class="flex flex-col mx-6">
                    <div class="box-title">
                        {{ $storage->name }}
                    </div>
                    <div class="box-description">
                        {{ $storage->description }}</div>
                </div>
            </div>
        @empty
            <div>
                <div>No storage found.</div>
            </div>
        @endforelse
    </div>
    <script>
        function goto(uuid) {
            window.location.href = '/storages/' + uuid;
        }
    </script>
</div>
