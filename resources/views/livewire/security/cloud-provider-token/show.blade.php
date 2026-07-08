<div>
    <x-slot:title>
        Cloud Token | Coolify
    </x-slot>
    <x-security.navbar />
    <form class="flex flex-col" wire:submit="save">
        <div class="flex items-start gap-2">
            <h2 class="pb-4">Cloud Token</h2>
            <x-forms.button canGate="update" :canResource="$cloudProviderToken" type="submit">
                Save
            </x-forms.button>
            <x-forms.button canGate="view" :canResource="$cloudProviderToken" type="button" wire:click="validateToken"
                :showLoadingIndicator="false" wire:loading.attr="disabled" wire:target="validateToken">
                Validate
                <x-loading-on-button wire:loading wire:target="validateToken" />
            </x-forms.button>
            @can('delete', $cloudProviderToken)
                <x-modal-confirmation title="Confirm Token Deletion?" isErrorButton buttonTitle="Delete"
                    submitAction="delete" :actions="[
                        'This cloud provider token will be permanently deleted.',
                        'Any servers using this token will need to be reconfigured.',
                    ]"
                    confirmationText="{{ $cloudProviderToken->name }}"
                    confirmationLabel="Please confirm the deletion by entering the token name below"
                    shortConfirmationLabel="Token Name" :confirmWithPassword="false" step2ButtonText="Delete Token" />
            @endcan
        </div>
        <div class="flex flex-col gap-2">
            <div class="flex gap-2">
                <x-forms.input canGate="update" :canResource="$cloudProviderToken" id="name" label="Name" required />
                <x-forms.input canGate="update" :canResource="$cloudProviderToken" id="description" label="Description" />
            </div>
            <div class="flex gap-2">
                <x-forms.input readonly label="Provider" :value="$this->providerName()" />
                <x-forms.input readonly label="Created" :value="$cloudProviderToken->created_at->diffForHumans()" />
            </div>
        </div>
    </form>
</div>
