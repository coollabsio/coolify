<div>
    <x-slot:title>
        {{ $cloudProviderToken->name }} | Cloud Tokens | Coolify
    </x-slot>

    <x-security.navbar :title="$cloudProviderToken->name"
        :subtitle="filled($cloudProviderToken->description) ? $cloudProviderToken->description : 'Cloud provider API credential'"
        :titleOnDesktop="true">
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
    </x-security.navbar>

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
</div>
