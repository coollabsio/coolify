<div x-init="$wire.loadPublicKey()">
    <x-slot:title>
        {{ $private_key->name }} | Private Keys | Coolify
    </x-slot>

    <x-security.navbar>
        <x-slot:actions>
            @if ($isGitRelated)
                <x-status-badge label="Used by GitHub App" type="neutral" />
            @endif
            @if (data_get($private_key, 'id') > 0)
                @can('delete', $private_key)
                    <x-modal-confirmation title="Confirm Private Key Deletion?" isErrorButton buttonTitle="Delete"
                        submitAction="delete({{ $private_key->id }})" :disabled="$isInUse"
                        :disabledTooltip="$deleteDisabledReason" :actions="[
                            'This private key will be permanently deleted.',
                            'Servers and Git sources using it will stop working.',
                        ]"
                        confirmationText="{{ $private_key->name }}"
                        confirmationLabel="Enter the private key name to confirm deletion"
                        shortConfirmationLabel="Private key name" :confirmWithPassword="false"
                        step2ButtonText="Delete private key" />
                @endcan
            @endif
        </x-slot:actions>
    </x-security.navbar>

    <form wire:submit="changePrivateKey" class="application-settings-form" x-data="{ showPrivateKey: false }">
        <x-unsaved-bar action="changePrivateKey" />
        <x-application.settings-section title="{{ $private_key->name }}">
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input canGate="update" :canResource="$private_key" id="name" label="Name" required />
                <x-forms.input canGate="update" :canResource="$private_key" id="description"
                    label="Description" />
                <div class="lg:col-span-2">
                    <x-forms.input canGate="update" :canResource="$private_key" readonly id="public_key"
                        label="Public key"
                        helper="Copy this value to ~/.ssh/authorized_keys on the target server." />
                </div>
                <div class="lg:col-span-2">
                    <div class="mb-1.5 flex items-center justify-between gap-3">
                        <label class="text-[13px] font-medium">Private key <span class="text-helper">*</span></label>
                        <button type="button"
                            class="text-[11px] font-medium text-coollabs hover:underline dark:text-warning"
                            x-on:click="showPrivateKey = !showPrivateKey"
                            x-text="showPrivateKey ? 'Hide editor' : 'Edit key'"></button>
                    </div>
                    <div x-show="!showPrivateKey">
                        <x-forms.input canGate="update" :canResource="$private_key" allowToPeak="false"
                            type="password" id="privateKeyValue" required disabled />
                    </div>
                    <div x-cloak x-show="showPrivateKey">
                        <x-forms.textarea canGate="update" :canResource="$private_key" rows="12"
                            id="privateKeyValue" required monospace />
                    </div>
                </div>
            </div>
        </x-application.settings-section>
    </form>
</div>
