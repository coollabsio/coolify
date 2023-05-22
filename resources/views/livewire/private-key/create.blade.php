<div>
    <form class="flex flex-col gap-2 " wire:submit.prevent='createPrivateKey'>
        <x-inputs.input id="name" label="Name" required />
        <x-inputs.input id="description" label="Description" />
        <x-inputs.textarea id="value" rows="10" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
            label="Private Key" required />
        <x-inputs.button type="submit" wire.click.prevent>
            Save
        </x-inputs.button>
    </form>
</div>
