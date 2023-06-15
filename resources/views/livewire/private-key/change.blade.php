<div x-data="{ deletePrivateKey: false, showPrivateKey: false }">
    <x-naked-modal show="deletePrivateKey" message='Are you sure you would like to delete this private key?' />
    <form class="flex flex-col gap-2" wire:submit.prevent='changePrivateKey'>
        <div class="flex items-end gap-2">
            <h1>Private Key</h1>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            <x-forms.button x-on:click.prevent="deletePrivateKey = true">
                Delete
            </x-forms.button>
        </div>
        <div class="pt-2 pb-8 text-sm">Private Key used for SSH connection</div>
        <x-forms.input id="private_key.name" label="Name" required />
        <x-forms.input id="private_key.description" label="Description" />
        <div>
            <div class="flex items-end gap-2 py-2 ">
                <div class="pl-1 text-sm">Private Key <span class='text-helper'>*</span></div>
                <div class="text-xs text-white underline cursor-pointer" x-cloak x-show="!showPrivateKey"
                    x-on:click="showPrivateKey = true">
                    Show
                </div>
                <div class="text-xs text-white underline cursor-pointer" x-cloak x-show="showPrivateKey"
                    x-on:click="showPrivateKey = false">
                    Hide
                </div>
            </div>
            <div x-cloak x-show="!showPrivateKey">
                <x-forms.input cannotPeak type="password" rows="10" id="private_key.private_key" required
                    disabled />
            </div>
            <div x-cloak x-show="showPrivateKey">
                <x-forms.textarea rows="10" id="private_key.private_key" required />
            </div>
        </div>

    </form>
</div>
