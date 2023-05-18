<div x-data="{ deleteStorage: false }">
    <form wire:submit.prevent='submit' class="flex flex-col px-2 max-w-fit">
        <div class="flex gap-2">
            <x-inputs.input id="storage.name" label="Name" required />
            <x-inputs.input id="storage.host_path" label="Source Path" />
            <x-inputs.input id="storage.mount_path" label="Destination Path" required />
        </div>
        <div class="pt-2">
            <x-inputs.button type="submit">
                Update
            </x-inputs.button>
            <x-inputs.button x-on:click.prevent="deleteStorage = true">
                Delete
            </x-inputs.button>
        </div>
    </form>
    <x-naked-modal show="deleteStorage" message="Are you sure you want to delete {{ $storage->name }}?" />
</div>
