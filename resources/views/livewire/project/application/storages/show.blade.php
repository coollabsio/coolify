<div x-data="{ deleteStorage: false }">
    <form wire:submit.prevent='submit' class="flex items-end gap-2 px-2">
        <x-inputs.input id="storage.name" label="Name" required />
        <x-inputs.input id="storage.mount_path" label="Mount Path (in your app)" required />
        <x-inputs.input id="storage.host_path" label="Mount Path (host)" />

        <x-inputs.button type="submit">
            Update
        </x-inputs.button>
        <x-inputs.button x-on:click.prevent="deleteStorage = true" isWarning>
            Delete
        </x-inputs.button>
    </form>
    <x-naked-modal show="deleteStorage" message="Are you sure you want to delete {{ $storage->name }}?" />
</div>
