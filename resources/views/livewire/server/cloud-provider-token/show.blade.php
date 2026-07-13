<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Cloud Token | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div class="flex flex-col h-full gap-4 md:gap-8 md:flex-row">
        <x-server.sidebar :server="$server" activeMenu="cloud-provider-token" />
        <div class="w-full">
            @if ($server->hetzner_server_id || $server->vultr_instance_id)
                <div class="flex items-end gap-2">
                    <h2>{{ $providerName }} Token</h2>
                    @can('create', App\Models\CloudProviderToken::class)
                        <x-modal-input buttonTitle="+ Add" title="Add {{ $providerName }} Token">
                            <livewire:security.cloud-provider-token-form :modal_mode="true" :provider="$provider" />
                        </x-modal-input>
                    @endcan
                    <x-forms.button canGate="update" :canResource="$server" isHighlighted
                        wire:click.prevent='validateToken' :showLoadingIndicator="false" wire:loading.attr="disabled"
                        wire:target="validateToken">
                        Validate token
                        <x-loading-on-button wire:loading wire:target="validateToken" />
                    </x-forms.button>
                </div>
                <div class="pb-4">Change your server's {{ $providerName }} token.</div>
                <div class="grid xl:grid-cols-2 grid-cols-1 gap-2">
                    @forelse ($cloudProviderTokens as $token)
                        <div
                            class="box-without-bg justify-between dark:bg-coolgray-100 bg-white items-center flex flex-col gap-2">
                            <div class="flex flex-col w-full">
                                <div class="box-title">{{ $token->name }}</div>
                                @if ($token->description)
                                    <div class="box-description">{{ $token->description }}</div>
                                @endif
                                <div class="box-description">
                                    Created {{ $token->created_at->diffForHumans() }}
                                </div>
                            </div>
                            @if (data_get($server, 'cloudProviderToken.id') !== $token->id)
                                <x-forms.button canGate="update" :canResource="$server" class="w-full"
                                    wire:click='setCloudProviderToken({{ $token->id }})'>
                                    Use this token
                                </x-forms.button>
                            @else
                                <x-forms.button class="w-full" disabled>
                                    Currently used
                                </x-forms.button>
                            @endif
                        </div>
                    @empty
                        <div>No {{ $providerName }} tokens found. </div>
                    @endforelse
                </div>
            @else
                <div class="flex items-end gap-2">
                    <h2>Cloud Token</h2>
                </div>
                <div class="pb-4">This server was not created through a supported cloud provider integration.</div>
                <div class="p-4 border rounded-md dark:border-coolgray-300 dark:bg-coolgray-100">
                    <p class="dark:text-neutral-400">
                        Only servers created through a supported cloud provider can have their tokens managed here.
                    </p>
                </div>
            @endif
        </div>
    </div>
</div>
