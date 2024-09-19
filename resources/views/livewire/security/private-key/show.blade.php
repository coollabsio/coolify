<div x-init="$wire.loadPublicKey()">
    <x-slot:title>
        Private Key | Coolify
    </x-slot>
    <x-security.navbar />
    <div x-data="{ showPrivateKey: false }">
        <form class="flex flex-col gap-2" wire:submit='changePrivateKey'>
            <div class="flex items-end gap-2">
                <h2>Private Key</h2>
                <x-forms.button type="submit">
                    Save
                </x-forms.button>
                @if (data_get($private_key, 'id') > 0)
                    <x-modal-confirmation 
                        title="Confirm Private Key Deletion?"
                        isErrorButton
                        buttonTitle="Delete"
                        submitAction="delete({{ $private_key->id }})"
                        :actions="['This private key will be permanently deleted.', 'All servers connected to this private key will stop working.', 'Any git app using this private key will stop working.']"
                        confirmationText="{{ $private_key->name }}"
                        confirmationLabel="Please confirm the execution of the actions by entering the Private Key Name below"
                        shortConfirmationLabel="Private Key Name"
                        :confirmWithPassword="false"
                        step2ButtonText="Delete Private Key"
                    />
                @endif
            </div>
            <x-forms.input id="private_key.name" label="Name" required />
            <x-forms.input id="private_key.description" label="Description" />
            <div>
                <div class="flex items-end gap-2 py-2 ">
                    <div class="pl-1 ">Public Key</div>
                </div>
                <x-forms.input readonly id="public_key" />
                <div class="flex items-end gap-2 py-2 ">
                    <div class="pl-1 ">Private Key <span class='text-helper'>*</span></div>
                    <div class="text-xs underline cursor-pointer dark:text-white" x-cloak x-show="!showPrivateKey"
                        x-on:click="showPrivateKey = true">
                        Edit
                    </div>
                    <div class="text-xs underline cursor-pointer dark:text-white" x-cloak x-show="showPrivateKey"
                        x-on:click="showPrivateKey = false">
                        Hide
                    </div>
                </div>
                @if (data_get($private_key, 'is_git_related'))
                    <div class="w-48">
                        <x-forms.checkbox id="private_key.is_git_related" disabled label="Is used by a Git App?" />
                    </div>
                @endif
                <div x-cloak x-show="!showPrivateKey">
                    <x-forms.input allowToPeak="false" type="password" rows="10" id="private_key.private_key"
                        required disabled />
                </div>
                <div x-cloak x-show="showPrivateKey">
                    <x-forms.textarea rows="10" id="private_key.private_key" required />
                </div>
            </div>
        </form>
    </div>
</div>
