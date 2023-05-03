<div>
    <form class="flex flex-col gap-2 w-96" wire:submit.prevent='changePrivateKey'>
        <x-form-input id="private_key_name" label="Name" required />
        <x-form-input id="private_key_description" label="Longer Description" />
        <x-form-input type="textarea" id="private_key_value" label="Private Key" required />
        <button type="submit">
            Submit
        </button>
        <button class="bg-red-500" @confirm.window="$wire.delete('{{ $private_key_uuid }}')"
            x-on:click="toggleConfirmModal('Are you sure you would like to delete this application?')">
            Delete
        </button>
    </form>
</div>
