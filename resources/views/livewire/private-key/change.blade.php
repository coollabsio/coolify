<div x-data="{ deletePrivateKey: false }">
    <x-naked-modal show="deletePrivateKey" message='Are you sure you would like to delete this private key?' />
    <form class="flex flex-col gap-2" wire:submit.prevent='changePrivateKey'>
        <x-forms.input id="private_key.name" label="Name" required />
        <x-forms.input id="private_key.description" label="Description" />
        <x-forms.textarea rows="10" id="private_key.private_key" label="Private Key" required />
        <div>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            <x-forms.button x-on:click.prevent="deletePrivateKey = true">
                Delete
            </x-forms.button>
        </div>
    </form>
</div>
