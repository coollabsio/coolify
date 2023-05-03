<div>
    <form class="flex flex-col gap-2 w-96" wire:submit.prevent='changePrivateKey'>
        <x-inputs.input id="private_key_name" label="Name" required />
        <x-inputs.input id="private_key_description" label="Longer Description" />
        <x-inputs.input type="textarea" id="private_key_value" label="Private Key" required />
        <x-inputs.button type="submit">
            Submit
        </x-inputs.button>
        <x-inputs.button class="bg-red-500" confirm='Are you sure you would like to delete this private key?'
            confirmAction="delete('{{ $private_key_uuid }}')">
            Delete
        </x-inputs.button>
    </form>
</div>
