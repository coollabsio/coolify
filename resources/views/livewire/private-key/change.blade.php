<div x-data="{ deletePrivateKey: false, showPrivateKey: false }">
    <x-naked-modal show="deletePrivateKey" title="Delete Private Key"
        message='This private key will be deleted. It is not reversible. <br>Please think again.' />
    <form class="flex flex-col gap-2" wire:submit.prevent='changePrivateKey'>
        <div class="flex items-end gap-2">
            <h1>Private Key</h1>
            @if ($private_key->id > 0)
                <x-forms.button type="submit">
                    Save
                </x-forms.button>
                <x-forms.button x-on:click.prevent="deletePrivateKey = true">
                    Delete
                </x-forms.button>
            @endif
        </div>
        <div class="pb-8">Private Key used for SSH connection</div>
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
            @if ($private_key->is_git_related)
                <div class="w-48">
                    <x-forms.checkbox id="private_key.is_git_related" disabled label="Is used by a Git App?" />
                </div>
            @endif
            <div x-cloak x-show="!showPrivateKey">
                <x-forms.input cannotPeakPassword type="password" rows="10" id="private_key.private_key" required
                    disabled />
            </div>
            <div x-cloak x-show="showPrivateKey">
                @if ($private_key->id === 0)
                    <x-forms.textarea rows="10" id="private_key.private_key" disabled />
                @else
                    <x-forms.textarea rows="10" id="private_key.private_key" required />
                @endif
            </div>
        </div>
    </form>
</div>
