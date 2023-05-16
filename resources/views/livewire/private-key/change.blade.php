<div x-data="{ deletePrivateKey: false }">
    <x-naked-modal show="deletePrivateKey" message='Are you sure you would like to delete this private key?' />
    <form class="flex flex-col gap-2" wire:submit.prevent='changePrivateKey'>
        <x-inputs.input id="private_key.name" label="Name" required />
        <x-inputs.input id="private_key.description" label="Description" />
        <x-inputs.input type="textarea" rows="10" id="private_key.private_key" label="Private Key" required />
        <div>
            <x-inputs.button isBold type="submit">
                Save
            </x-inputs.button>
            <x-inputs.button isWarning x-on:click.prevent="deletePrivateKey = true">
                Delete
            </x-inputs.button>
        </div>
    </form>
</div>
