<div class="flex flex-col w-full gap-2 max-h-[80vh] overflow-y-auto scrollbar">
    <form class="flex flex-col w-full gap-2 rounded " wire:submit='submitPersistentVolume'>
        <div class="flex flex-col">
            <h3>Volume Mount</h3>
            <div>Docker Volumes mounted to the container.</div>
        </div>
        @if ($isSwarm)
            <h5>Swarm Mode detected: You need to set a shared volume (EFS/NFS/etc) on all the worker nodes if you
                would
                like to use a persistent volumes.</h5>
        @endif
        <div class="flex flex-col gap-2 px-2">
            <x-forms.input placeholder="pv-name" id="name" label="Name" required helper="Volume name." />
            @if ($isSwarm)
                <x-forms.input placeholder="/root" id="host_path" label="Source Path" required
                    helper="Directory on the host system." />
            @else
                <x-forms.input placeholder="/root" id="host_path" label="Source Path"
                    helper="Directory on the host system." />
            @endif
            <x-forms.input placeholder="/tmp/root" id="mount_path" label="Destination Path" required
                helper="Directory inside the container." />
            <x-forms.button type="submit" @click="modalOpen=false">
                Add
            </x-forms.button>
        </div>

    </form>
    <form class="flex flex-col w-full gap-2 rounded py-4" wire:submit='submitFileStorage'>
        <div class="flex flex-col">
            <h3>File Mount</h3>
            <div>Actual file mounted from the host system to the container.</div>
        </div>
        <div class="flex flex-col gap-2 px-2">
            <x-forms.input placeholder="/etc/nginx/nginx.conf" id="file_storage_path" label="Destination Path" required
                helper="File location inside the container" />
            <x-forms.textarea label="Content" id="file_storage_content"></x-forms.textarea>
            <x-forms.button type="submit" @click="modalOpen=false">
                Add
            </x-forms.button>
        </div>
    </form>
    <form class="flex flex-col w-full gap-2 rounded" wire:submit='submitFileStorageDirectory'>
        <div class="flex flex-col">
            <h3>Directory Mount</h3>
            <div>Directory mounted from the host system to the container.</div>
        </div>
        <div class="flex flex-col gap-2 px-2">
            <x-forms.input placeholder="{{ application_configuration_dir() }}/{{ $resource->uuid }}/etc/nginx"
                id="file_storage_directory_source" label="Source Directory" required
                helper="Directory on the host system." />
            <x-forms.input placeholder="/etc/nginx" id="file_storage_directory_destination"
                label="Destination Directory" required helper="Directory inside the container." />
            <x-forms.button type="submit" @click="modalOpen=false">
                Add
            </x-forms.button>
        </div>
    </form>
</div>
