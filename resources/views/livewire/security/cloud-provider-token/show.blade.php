<div>
    <x-slot:title>
        {{ $cloudProviderToken->name }} | Cloud Tokens | Coolify
    </x-slot>

    <x-security.navbar>
        <x-slot:actions>
            <button type="button" class="button" wire:click="validateToken"
                wire:loading.attr="disabled" wire:target="validateToken">
                <x-reicon name="check-circle" class="size-3.5" />
                Validate
                <x-loading-on-button wire:loading wire:target="validateToken" />
            </button>
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
    </x-security.navbar>

    <form wire:submit="save" class="application-settings-form">
        <x-unsaved-bar action="save" />
        <x-application.settings-section title="{{ $cloudProviderToken->name }}"
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
</div>
