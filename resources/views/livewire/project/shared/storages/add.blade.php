<form class="flex flex-col w-full gap-2 rounded" wire:submit='submit'>
    @if ($isSwarm)
        <h5>Swarm Mode detected: You need to set a shared volume (EFS/NFS/etc) on all the worker nodes if you would
            like to use a persistent volumes.</h5>
    @endif
    <x-forms.input placeholder="pv-name" id="name" label="Name" required helper="Volume name." />
    @if ($isSwarm)
        <x-forms.input placeholder="/root" id="host_path" label="Source Path" required
            helper="Directory on the host system." />
    @else
        <x-forms.input placeholder="/root" id="host_path" label="Source Path" helper="Directory on the host system." />
    @endif
    <x-forms.input placeholder="/tmp/root" id="mount_path" label="Destination Path" required
        helper="Directory inside the container." />
    <x-forms.button type="submit" @click="slideOverOpen=false">
        Save
    </x-forms.button>
</form>
