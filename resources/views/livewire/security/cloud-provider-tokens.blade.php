<div>
    <div class="flex items-center gap-2">
        <h2>Cloud Provider Tokens</h2>
        @can('create', App\Models\CloudProviderToken::class)
            <div x-data="{ dropdownOpen: false }" class="relative w-fit" @click.outside="dropdownOpen = false">
                <x-forms.button isHighlighted @click="dropdownOpen = !dropdownOpen" type="button">
                    + Add
                    <svg class="w-4 h-4 ml-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                        stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" />
                    </svg>
                </x-forms.button>

                <div x-show="dropdownOpen" @click.away="dropdownOpen=false" x-transition:enter="ease-out duration-200"
                    x-transition:enter-start="-translate-y-2" x-transition:enter-end="translate-y-0"
                    class="absolute top-0 z-50 mt-10 min-w-max" x-cloak>
                    <div
                        class="p-1 mt-1 bg-white border rounded-sm shadow-sm dark:bg-coolgray-200 dark:border-coolgray-300 border-neutral-300">
                        <div class="flex flex-col gap-1">
                            <x-modal-input title="Add Hetzner Token">
                                <x-slot:content>
                                    <div class="dropdown-item" @click="dropdownOpen = false">
                                        <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M12 4.5v15m7.5-7.5h-15" />
                                        </svg>
                                        Hetzner
                                    </div>
                                </x-slot:content>
                                <livewire:security.cloud-provider-token-form :modal_mode="true" provider="hetzner"
                                    wire:key="cloud-provider-token-hetzner" />
                            </x-modal-input>

                            <x-modal-input title="Add DigitalOcean Token">
                                <x-slot:content>
                                    <div class="dropdown-item" @click="dropdownOpen = false">
                                        <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M12 4.5v15m7.5-7.5h-15" />
                                        </svg>
                                        DigitalOcean
                                    </div>
                                </x-slot:content>
                                <livewire:security.cloud-provider-token-form :modal_mode="true" provider="digitalocean"
                                    wire:key="cloud-provider-token-digitalocean" />
                            </x-modal-input>

                            <x-modal-input title="Add Vultr Token">
                                <x-slot:content>
                                    <div class="dropdown-item" @click="dropdownOpen = false">
                                        <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M12 4.5v15m7.5-7.5h-15" />
                                        </svg>
                                        Vultr
                                    </div>
                                </x-slot:content>
                                <livewire:security.cloud-provider-token-form :modal_mode="true" provider="vultr"
                                    wire:key="cloud-provider-token-vultr" />
                            </x-modal-input>
                        </div>
                    </div>
                </div>
            </div>
        @endcan
    </div>
    <div class="pb-4">Manage API tokens for cloud providers (Hetzner, Vultr, etc.).</div>
    <div class="grid gap-4 lg:grid-cols-2">
        @forelse ($tokens as $savedToken)
            <a wire:key="token-{{ $savedToken->id }}" class="coolbox group"
                href="{{ route('security.cloud-tokens.show', ['cloud_token_uuid' => $savedToken->uuid]) }}" {{ wireNavigate() }}>
                <div class="flex flex-col justify-center mx-6">
                    <div class="box-title">
                        {{ $savedToken->name }}
                    </div>
                    <div class="box-description">
                        {{ strtoupper($savedToken->provider) }}
                    @if ($savedToken->description)
                        · {{ $savedToken->description }}
                    @endif
                </div>
            </div>
        </a>
        @empty
            <div>
                <div>No cloud provider tokens found.</div>
            </div>
        @endforelse
    </div>
</div>
