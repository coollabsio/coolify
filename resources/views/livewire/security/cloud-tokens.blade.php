<div>
    <x-slot:title>
        Cloud Tokens | Coolify
    </x-slot>

    <x-security.navbar>
        <x-slot:actions>
            @can('create', App\Models\CloudProviderToken::class)
                <x-modal-input title="New Cloud Token">
                    <x-slot:content>
                        <button type="button"
                            class="button bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!">
                            <x-reicon name="plus" class="size-3.5" />
                            New token
                        </button>
                    </x-slot:content>
                    <livewire:security.cloud-provider-token-form :modal_mode="true"
                        wire:key="new-cloud-provider-token" />
                </x-modal-input>
            @endcan
        </x-slot:actions>
    </x-security.navbar>

    <livewire:security.cloud-provider-tokens />
</div>
