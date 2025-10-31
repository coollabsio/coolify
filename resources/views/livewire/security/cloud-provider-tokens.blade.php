<div>
    <h2>Cloud Provider Tokens</h2>
    <div class="pb-4">Manage API tokens for cloud providers (Hetzner, DigitalOcean, etc.).</div>

    <h3>New Token</h3>
    @can('create', App\Models\CloudProviderToken::class)
        <livewire:security.cloud-provider-token-form :modal_mode="false" />
    @endcan

    <h3 class="py-4">Saved Tokens</h3>
    <div class="grid gap-2 lg:grid-cols-1">
        @forelse ($tokens as $savedToken)
            <div wire:key="token-{{ $savedToken->id }}"
                class="flex flex-col gap-1 p-2 border dark:border-coolgray-200 hover:no-underline">
                <div class="flex items-center gap-2">
                    <span class="px-2 py-1 text-xs font-bold rounded dark:bg-coolgray-300 dark:text-white">
                        {{ strtoupper($savedToken->provider) }}
                    </span>
                    <span class="font-bold dark:text-white">{{ $savedToken->name }}</span>
                </div>
                <div class="text-sm">Created: {{ $savedToken->created_at->diffForHumans() }}</div>

                <div class="flex gap-2 pt-2">
                    @can('view', $savedToken)
                        <x-forms.button wire:click="validateToken({{ $savedToken->id }})" type="button">
                            Validate Token
                        </x-forms.button>
                    @endcan

                    @can('delete', $savedToken)
                        <x-modal-confirmation title="Confirm Token Deletion?" isErrorButton buttonTitle="Delete Token"
                            submitAction="deleteToken({{ $savedToken->id }})" :actions="[
                                'This cloud provider token will be permanently deleted.',
                                'Any servers using this token will need to be reconfigured.',
                            ]"
                            confirmationText="{{ $savedToken->name }}"
                            confirmationLabel="Please confirm the deletion by entering the token name below"
                            shortConfirmationLabel="Token Name" :confirmWithPassword="false" step2ButtonText="Delete Token" />
                    @endcan
                </div>
            </div>
        @empty
            <div>
                <div>No cloud provider tokens found.</div>
            </div>
        @endforelse
    </div>
</div>
