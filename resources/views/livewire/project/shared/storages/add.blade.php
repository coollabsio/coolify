<dialog id="newStorage" class="modal">
    <form method="dialog" class="flex flex-col gap-2 rounded modal-box" wire:submit='submit'>
        <h3 class="text-lg font-bold">Add Storage Volume</h3>
        @if ($isSwarm)
        <h5>Swarm Mode detected: You need to set a shared volume (EFS/NFS/etc) on all the worker nodes if you would like to use a persistent volumes.</h5>
        @endif
        <x-forms.input placeholder="pv-name" id="name" label="Name" required helper="Volume name."  />
        @if ($isSwarm)
            <x-forms.input placeholder="/root" id="host_path" label="Source Path" required helper="Directory on the host system." />
        @else
            <x-forms.input placeholder="/root" id="host_path" label="Source Path"  helper="Directory on the host system." />
        @endif
        <x-forms.input placeholder="/tmp/root" id="mount_path" label="Destination Path" required helper="Directory inside the container." />
        <x-forms.button type="submit">
            Save
        </x-forms.button>
    </form>
    <form method="dialog" class="modal-backdrop">
        <button>close</button>
    </form>
    <script>
        document.addEventListener('livewire:initialized', () => {
            Livewire.on('closeStorageModal', () => {
                document.getElementById('newStorage').close()
            })
        })
    </script>
</dialog>
