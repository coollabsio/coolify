<div>
    <div class="pb-2 subtitle">
        <div>Private Keys are used to connect to your servers without passwords.</div>
        <div class="font-bold">{{ __('security.should_not_use_passphrase') }}</div>
    </div>
    <div class="flex gap-2 mb-4 w-full">
        <x-forms.button wire:click="generateNewEDKey" isHighlighted class="w-full">{{ __('security.generate_ed25519_key') }}</x-forms.button>
        <x-forms.button wire:click="generateNewRSAKey">{{ __('security.generate_rsa_key') }}</x-forms.button>
    </div>
    <form class="flex flex-col gap-2" wire:submit='createPrivateKey'>
        <div class="flex gap-2">
            <x-forms.input id="name" label="{{ __('security.name_label') }}" required />
            <x-forms.input id="description" label="{{ __('security.description_label') }}" />
        </div>
        <x-forms.textarea realtimeValidation id="value" rows="10"
            placeholder="-----BEGIN OPENSSH PRIVATE KEY-----" label="{{ __('security.private_key_label') }}" required />
        <x-forms.input id="publicKey" readonly label="{{ __('security.public_key_label') }}" />
        <span class="pt-2 pb-4 font-bold dark:text-warning">{{ __('security.action_required_copy_public_key') }}</span>
        <x-forms.button type="submit">{{ __('common.continue') }}</x-forms.button>
    </form>
</div>
