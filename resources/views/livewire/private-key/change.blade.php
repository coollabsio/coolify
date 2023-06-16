<div x-data="{ deletePrivateKey: false, showPrivateKey: false }">
    <x-naked-modal show="deletePrivateKey" title="Delete Private Key"
        message='This private key will be deleted. It is not reversible. <br>Please think again.' />
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
        <div class="pb-8 ">Private Key used for SSH connection</div>
        <x-forms.input id="private_key.name" label="Name" required />
        <x-forms.input id="private_key.description" label="Description" />
        <div>
            <div class="flex items-end gap-2 py-2 ">
                <div class="pl-1 ">Private Key <span class='text-helper'>*</span></div>
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
                <x-forms.input cannotPeakPassword type="password" rows="10" id="private_key.private_key" required
                    disabled />
            </div>
            <div x-cloak x-show="showPrivateKey">
                <x-forms.textarea rows="10" id="private_key.private_key" required />
            </div>
        </div>

    </form>
</div>
