<div>
    <form class="flex flex-col gap-2 " wire:submit.prevent='createPrivateKey'>
        <x-forms.input id="name" label="Name" required />
        <x-forms.input id="description" label="Description" />
        <x-forms.textarea id="value" rows="10" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
            label="Private Key" required />
        <x-forms.button type="submit" wire.click.prevent>
            Save
        </x-forms.button>
    </form>
</div>
