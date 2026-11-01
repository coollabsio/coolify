<div>
    @if ($modalMode)
        <div class="relative">
        <form wire:submit="changePrivateKey" class="flex flex-col gap-4"
            wire:loading.class="pointer-events-none opacity-50" wire:target="delete">
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input canGate="update" :canResource="$private_key" id="name" label="Name" required />
                <x-forms.input canGate="update" :canResource="$private_key" id="description" label="Description" />
                <div class="lg:col-span-2">
                    <x-forms.input canGate="update" :canResource="$private_key" readonly id="public_key"
                        label="Public key" helper="Copy this value to ~/.ssh/authorized_keys on the target server." />
                </div>
                @include('livewire.security.private-key.partials.key-field')
            </div>
            <div class="flex items-center justify-between gap-2 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                @can('delete', $private_key)
                    <x-modal-confirmation title="Confirm Private Key Deletion?" isErrorButton buttonTitle="Delete"
                        submitAction="delete" :disabled="$isInUse" :disabledTooltip="$deleteDisabledReason"
                        :actions="['This private key will be permanently deleted.']" confirmationText="{{ $private_key->name }}"
                        :confirmWithPassword="false" step2ButtonText="Delete private key" />
                @endcan
                <x-forms.button type="submit" isHighlighted>Save changes</x-forms.button>
            </div>
        </form>
        <div wire:loading.flex wire:target="delete"
            class="absolute inset-0 z-10 items-center justify-center rounded-lg bg-white/50 dark:bg-black/40">
            <x-loading text="Deleting private key..." />
        </div>
        </div>
    @else
    <x-slot:title>
        {{ $private_key->name }} | Private Keys | Coolify
    </x-slot>

    <x-security.settings-layout>
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


    <form wire:submit="changePrivateKey" class="application-settings-form">
        <x-unsaved-bar action="changePrivateKey" />
        <x-application.settings-section title="General">
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input canGate="update" :canResource="$private_key" id="name" label="Name" required />
                <x-forms.input canGate="update" :canResource="$private_key" id="description"
                    label="Description" />
                <div class="lg:col-span-2">
                    <x-forms.input canGate="update" :canResource="$private_key" readonly id="public_key"
                        label="Public key"
                        helper="Copy this value to ~/.ssh/authorized_keys on the target server." />
                </div>
                @include('livewire.security.private-key.partials.key-field')
            </div>
        </x-application.settings-section>
    </form>
    </x-security.settings-layout>
    @endif
</div>
