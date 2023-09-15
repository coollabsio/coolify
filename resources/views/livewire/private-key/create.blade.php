<div>
    <form class="flex flex-col gap-2" wire:submit.prevent='createPrivateKey'>
        <div class="flex gap-2">
            <x-forms.input id="name" label="Name" required />
            <x-forms.input id="description" label="Description" />
        </div>
        <x-forms.textarea realtimeValidation id="value" rows="10"
            placeholder="-----BEGIN OPENSSH PRIVATE KEY-----" label="Private Key" required />
        <x-forms.button wire:click="generateNewKey">Generate new SSH key for me</x-forms.button>
        <x-forms.textarea id="publicKey" rows="6" readonly label="Public Key" />
        <span class="font-bold text-warning">ACTION REQUIRED: Copy the 'Public Key' to your server's
            ~/.ssh/authorized_keys
            file.</span>
        <x-forms.button type="submit">
            Save Private Key
        </x-forms.button>
    </form>
</div>
