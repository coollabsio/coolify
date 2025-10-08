<div>
    <h2>Cloud Provider Tokens</h2>
    <div class="pb-4">Manage API tokens for cloud providers (Hetzner, DigitalOcean, etc.). Tokens are saved encrypted and shared with your team.</div>

    <h3>New Token</h3>
    @can('create', App\Models\CloudProviderToken::class)
        <form class="flex flex-col gap-2" wire:submit='addNewToken'>
            <div class="flex gap-2 items-end flex-wrap">
                <div class="w-64">
                    <x-forms.select required id="provider" label="Provider">
                        <option value="hetzner">Hetzner</option>
                        <option value="digitalocean">DigitalOcean</option>
                    </x-forms.select>
                </div>
                <div class="flex-1 min-w-64">
                    <x-forms.input required id="name" label="Token Name" placeholder="e.g., Production Hetzner" />
                </div>
            </div>
            <div class="flex gap-2 items-end flex-wrap">
                <div class="flex-1 min-w-64">
                    <x-forms.input required type="password" id="token" label="API Token" placeholder="Enter your API token" />
                </div>
                <x-forms.button type="submit">Add Token</x-forms.button>
            </div>
        </form>
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
                <div class="text-sm">Token: ***{{ substr($savedToken->token, -4) }}</div>
                <div class="text-sm">Created: {{ $savedToken->created_at->diffForHumans() }}</div>

                @can('delete', $savedToken)
                    <x-modal-confirmation
                        title="Confirm Token Deletion?"
                        isErrorButton
                        buttonTitle="Delete Token"
                        submitAction="deleteToken({{ $savedToken->id }})"
                        :actions="[
                            'This cloud provider token will be permanently deleted.',
                            'Any servers using this token will need to be reconfigured.',
                        ]"
                        confirmationText="{{ $savedToken->name }}"
                        confirmationLabel="Please confirm the deletion by entering the token name below"
                        shortConfirmationLabel="Token Name"
                        :confirmWithPassword="false"
                        step2ButtonText="Delete Token"
                    />
                @endcan
            </div>
        @empty
            <div>
                <div>No cloud provider tokens found.</div>
            </div>
        @endforelse
    </div>
</div>
