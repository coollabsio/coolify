<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Hetzner Token | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="cloud-provider-token" />
        <div class="w-full">
            @if ($server->hetzner_server_id)
                <div class="flex items-end gap-2">
                    <h2>{{ __('server.hetzner_token') }}</h2>
                    @can('create', App\Models\CloudProviderToken::class)
                        <x-modal-input buttonTitle="{{ __('button.add') }}" title="{{ __('modal.add_hetzner_token') }}">
                            <livewire:security.cloud-provider-token-form :modal_mode="true" provider="hetzner" />
                        </x-modal-input>
                    @endcan
                    <x-forms.button canGate="update" :canResource="$server" isHighlighted
                        wire:click.prevent='validateToken'>
                        {{ __('server.validate_token') }}
                    </x-forms.button>
                </div>
                <div class="pb-4">{{ __('server.change_server_hetzner_token') }}</div>
                <div class="grid xl:grid-cols-2 grid-cols-1 gap-2">
                    @forelse ($cloudProviderTokens as $token)
                        <div
                            class="box-without-bg justify-between dark:bg-coolgray-100 bg-white items-center flex flex-col gap-2">
                            <div class="flex flex-col w-full">
                                <div class="box-title">{{ $token->name }}</div>
                                <div class="box-description">
                                    Created {{ $token->created_at->diffForHumans() }}
                                </div>
                            </div>
                            @if (data_get($server, 'cloudProviderToken.id') !== $token->id)
                                <x-forms.button canGate="update" :canResource="$server" class="w-full"
                                    wire:click='setCloudProviderToken({{ $token->id }})'>
                                    {{ __('server.use_this_token') }}
                                </x-forms.button>
                            @else
                                <x-forms.button class="w-full" disabled>
                                    {{ __('server.currently_used') }}
                                </x-forms.button>
                            @endif
                        </div>
                    @empty
                        <div>{{ __('server.no_hetzner_tokens_found') }}</div>
                    @endforelse
                </div>
            @else
                <div class="flex items-end gap-2">
                    <h2>{{ __('server.hetzner_token') }}</h2>
                </div>
                <div class="pb-4">{{ __('server.server_not_created_hetzner') }}</div>
                <div class="p-4 border rounded-md dark:border-coolgray-300 dark:bg-coolgray-100">
                    <p class="dark:text-neutral-400">
                        {{ __('server.only_hetzner_servers') }}
                    </p>
                </div>
            @endif
        </div>
    </div>
</div>
