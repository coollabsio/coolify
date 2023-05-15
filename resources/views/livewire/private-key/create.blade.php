<div>
    <form class="flex flex-col gap-2 " wire:submit.prevent='createPrivateKey'>
        <x-inputs.input id="name" label="Name" required />
        <x-inputs.input id="description" label="Description" />
        <x-inputs.input type="textarea" id="value" label="Private Key" required />
        <x-inputs.button isBold type="submit" wire.click.prevent>
            Save
        </x-inputs.button>
    </form>
</div>
