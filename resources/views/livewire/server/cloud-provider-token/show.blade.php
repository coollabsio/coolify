<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Cloud Token | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-8 grid w-full max-w-[1180px] min-w-0 gap-8 xl:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
        <x-server.sidebar :server="$server" activeMenu="cloud-provider-token" />

        <div class="application-settings-form w-full">
            @if ($server->hetzner_server_id || $server->vultr_instance_id)
                <x-application.settings-section id="server-cloud-token-section"
                    title="{{ $providerName }} token"
                    helper="Choose the cloud credential used to manage this server." flush>
                    <x-slot:actions>
                        <div class="flex items-center gap-2">
                            <x-forms.button canGate="update" :canResource="$server"
                                wire:click.prevent="validateToken" :showLoadingIndicator="false"
                                wire:loading.attr="disabled" wire:target="validateToken">
                                <x-reicon name="refresh" class="size-3.5" />
                                Validate token
                                <x-loading-on-button wire:loading wire:target="validateToken" />
                            </x-forms.button>
                            @can('create', App\Models\CloudProviderToken::class)
                                <x-modal-input buttonTitle="+ Add" title="Add {{ $providerName }} Token">
                                    <livewire:security.cloud-provider-token-form :modal_mode="true"
                                        :provider="$provider" />
                                </x-modal-input>
                            @endcan
                        </div>
                    </x-slot:actions>

                    @forelse ($cloudProviderTokens as $token)
                        <div
                            class="flex items-center gap-4 border-b border-neutral-200 px-4 py-3 last:border-b-0 dark:border-white/[0.08]">
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 dark:bg-white/[0.06] dark:text-fg-dim">
                                <x-reicon name="keys" class="size-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="truncate text-sm font-medium text-neutral-950 dark:text-fg">
                                        {{ $token->name }}
                                    </p>
                                    @if (data_get($server, 'cloudProviderToken.id') === $token->id)
                                        <x-status-badge status="Active" type="success" />
                                    @endif
                                </div>
                                <p class="mt-0.5 text-xs text-neutral-500 dark:text-fg-dim">
                                    {{ $token->description ?: 'Created ' . $token->created_at->diffForHumans() }}
                                </p>
                            </div>
                            @if (data_get($server, 'cloudProviderToken.id') !== $token->id)
                                <x-forms.button canGate="update" :canResource="$server"
                                    wire:click="setCloudProviderToken({{ $token->id }})">
                                    Use this token
                                </x-forms.button>
                            @endif
                        </div>
                    @empty
                        <x-empty size="sm" title="No {{ $providerName }} tokens"
                            description="Add a token to manage this server through {{ $providerName }}.">
                            <x-slot:icon>
                                <x-reicon name="keys" class="size-8" />
                            </x-slot:icon>
                        </x-empty>
                    @endforelse
                </x-application.settings-section>
            @else
                <x-application.settings-section title="Cloud token"
                    helper="Cloud credentials are available for servers created through a supported provider.">
                    <x-empty size="sm" title="No cloud provider integration"
                        description="This server was not created through Hetzner or Vultr, so it does not require a managed cloud token.">
                        <x-slot:icon>
                            <x-reicon name="keys" class="size-8" />
                        </x-slot:icon>
                    </x-empty>
                </x-application.settings-section>
            @endif
        </div>
    </div>
</div>
