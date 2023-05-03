<div>
    <form class="flex flex-col gap-2 w-96" wire:submit.prevent='createPrivateKey'>
        <x-inputs.input id="private_key_name" label="Name" required />
        <x-inputs.input id="private_key_description" label="Longer Description" />
        <x-inputs.input type="textarea" id="private_key_value" label="Private Key" required />
        <x-inputs.button type="submit">
            Submit
        </x-inputs.button>
    </form>
</div>
