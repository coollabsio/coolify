<div>
    <form class="flex flex-col gap-2 w-96" wire:submit.prevent='createPrivateKey'>
        <x-form-input id="private_key_name" label="Name" required />
        <x-form-input id="private_key_description" label="Longer Description" />
        <x-form-input type="textarea" id="private_key_value" label="Private Key" required />
        <button type="submit">
            Submit
        </button>
    </form>
</div>
