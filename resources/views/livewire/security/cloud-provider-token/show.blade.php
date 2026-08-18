<div>
    @if ($modalMode)
        <form wire:submit="save" class="flex flex-col gap-4">
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input canGate="update" :canResource="$cloudProviderToken" id="name" label="Name" required />
                <x-forms.input canGate="update" :canResource="$cloudProviderToken" id="description" label="Description" />
                <x-forms.input readonly label="Provider" :value="$this->providerName()" />
                <x-forms.input readonly label="Created" :value="$cloudProviderToken->created_at->format('Y-m-d H:i')" />
            </div>
            <div class="flex items-center justify-between gap-2 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                <div class="flex items-center gap-2">
                    @can('delete', $cloudProviderToken)
                        <x-modal-confirmation title="Confirm Token Deletion?" isErrorButton buttonTitle="Delete"
                            submitAction="delete" :actions="['This cloud provider token will be permanently deleted.']"
                            confirmationText="{{ $cloudProviderToken->name }}" :confirmWithPassword="false"
                            step2ButtonText="Delete token" />
                    @endcan
                    <x-forms.button type="button" wire:click="validateToken">Validate</x-forms.button>
                </div>
                <x-forms.button type="submit" isHighlighted>Save changes</x-forms.button>
            </div>
        </form>
    @else
    <x-slot:title>
        {{ $cloudProviderToken->name }} | Cloud Tokens | Coolify
    </x-slot>

    <x-security.settings-layout>
        <x-slot:actions>
            <x-forms.button type="button" wire:click="validateToken">
                <x-reicon name="check-circle" class="size-3.5" />
                Validate
            </x-forms.button>
            @can('delete', $cloudProviderToken)
                <x-modal-confirmation title="Confirm Token Deletion?" isErrorButton buttonTitle="Delete"
                    submitAction="delete" :actions="[
                        'This cloud provider token will be permanently deleted.',
                        'Servers using this token will need to be reconfigured.',
                    ]" confirmationText="{{ $cloudProviderToken->name }}"
                    confirmationLabel="Enter the token name to confirm deletion"
                    shortConfirmationLabel="Token name" :confirmWithPassword="false"
                    step2ButtonText="Delete token" />
            @endcan
        </x-slot:actions>


    <form wire:submit="save" class="application-settings-form">
        <x-unsaved-bar action="save" />
        <x-application.settings-section title="General"
            description="Identity and provider details for this cloud API credential.">
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input canGate="update" :canResource="$cloudProviderToken" id="name"
                    label="Name" required />
                <x-forms.input canGate="update" :canResource="$cloudProviderToken" id="description"
                    label="Description" />
                <x-forms.input readonly label="Provider" :value="$this->providerName()" />
                <x-forms.input readonly label="Created" :value="$cloudProviderToken->created_at->format('Y-m-d H:i')" />
            </div>
        </x-application.settings-section>
    </form>
    </x-security.settings-layout>
    @endif
</div>
