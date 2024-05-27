<div class="flex flex-col w-full gap-2 rounded">
    You can add Volumes, Files and Directories to your resources here.
    <form class="flex flex-col w-full gap-2 rounded" wire:submit='submitPersistentVolume'>
        <h3>Volume Mount</h3>
        @if ($isSwarm)
            <h5>Swarm Mode detected: You need to set a shared volume (EFS/NFS/etc) on all the worker nodes if you would
                like to use a persistent volumes.</h5>
        @endif
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
            Save
        </x-forms.button>
    </form>
    <form class="flex flex-col w-full gap-2 rounded" wire:submit='submitFileStorage'>
        <h3>File Mount</h3>
        <x-forms.input placeholder="/etc/nginx/nginx.conf" id="file_storage_path" label="Destination Path" required
            helper="File inside the container" />
        <x-forms.textarea label="Content" id="file_storage_content"></x-forms.textarea>
        <x-forms.button type="submit" @click="modalOpen=false">
            Save
        </x-forms.button>
    </form>
    <form class="flex flex-col w-full gap-2 rounded" wire:submit='submitFileStorageDirectory'>
        <h3>Directory Mount</h3>
        <x-forms.input placeholder="{{ application_configuration_dir() }}/{{ $resource->uuid }}/etc/nginx"
            id="file_storage_directory_source" label="Source Directory" required
            helper="Directory on the host system." />
        <x-forms.input placeholder="/etc/nginx" id="file_storage_directory_destination" label="Destination Directory"
            required helper="Directory inside the container." />
        <x-forms.button type="submit" @click="modalOpen=false">
            Save
        </x-forms.button>
    </form>
</div>
