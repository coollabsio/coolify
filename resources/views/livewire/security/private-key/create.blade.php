<div>
    {{-- <div class="subtitle">Private Keys are used to connect to your servers without passwords.</div> --}}
    <x-forms.button class="mb-4" wire:click="generateNewKey">Generate new SSH key for me</x-forms.button>
    <form class="flex flex-col gap-2" wire:submit='createPrivateKey'>
        <div class="flex gap-2">
            <x-forms.input id="name" label="Name" required />
            <x-forms.input id="description" label="Description" />
        </div>
        <x-forms.textarea realtimeValidation id="value" rows="10"
            placeholder="-----BEGIN OPENSSH PRIVATE KEY-----" label="Private Key" required />
        <x-forms.input id="publicKey" readonly label="Public Key" />
        <span class="pt-2 pb-4 font-bold dark:text-warning">ACTION REQUIRED: Copy the 'Public Key' to your server's
            ~/.ssh/authorized_keys
            file</span>
        <x-forms.button type="submit">
            Continue
        </x-forms.button>
    </form>
</div>
