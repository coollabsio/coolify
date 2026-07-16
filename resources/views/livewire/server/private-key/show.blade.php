<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Private Key | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div class="flex flex-col h-full gap-4 md:gap-8 md:flex-row">
        <x-server.sidebar :server="$server" activeMenu="private-key" />
        <div class="w-full">
            <div class="flex items-end gap-2">
                <h2>Private Key</h2>
                @can('createAnyResource')
                    <div x-data="{ dropdownOpen: false }" class="relative w-fit" @click.outside="dropdownOpen = false">
                        <x-forms.button isHighlighted @click="dropdownOpen = !dropdownOpen" type="button">
                            + Add
                            <svg class="w-4 h-4 ml-2" xmlns="http://www.w3.org/2000/svg" fill="none"
                                viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
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
                                    <a class="dropdown-item" wire:click="generatePrivateKey('ed25519')"
                                        @click="dropdownOpen = false">
                                        <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M12 4.5v15m7.5-7.5h-15" />
                                        </svg>
                                        Generate ED25519
                                    </a>
                                    <a class="dropdown-item" wire:click="generatePrivateKey('rsa')"
                                        @click="dropdownOpen = false">
                                        <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M12 4.5v15m7.5-7.5h-15" />
                                        </svg>
                                        Generate RSA
                                    </a>
                                    <x-modal-input title="Add Private Key Manually">
                                        <x-slot:content>
                                            <div class="dropdown-item" @click="dropdownOpen = false">
                                                <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M12 4.5v15m7.5-7.5h-15" />
                                                </svg>
                                                Add manually
                                            </div>
                                        </x-slot:content>
                                        <livewire:security.private-key.create />
                                    </x-modal-input>
                                </div>
                            </div>
                        </div>
                    </div>
                @endcan
                <x-forms.button canGate="update" :canResource="$server" isHighlighted wire:click.prevent='checkConnection'>
                    Check connection
                </x-forms.button>
            </div>
            <div class="pb-4">Change your server's private key.</div>
            <div class="grid xl:grid-cols-2 grid-cols-1 gap-2">
                @forelse ($privateKeys as $private_key)
                    <div
                        class="box-without-bg justify-between dark:bg-coolgray-100 bg-white items-center flex flex-col gap-2">
                        <div class="flex flex-col w-full">
                            <div class="box-title">{{ $private_key->name }}</div>
                            <div class="box-description">{{ $private_key->description }}</div>
                        </div>
                        <div class="grid w-full grid-cols-1 gap-2 sm:grid-cols-2">
                            <x-forms.button class="w-full"
                                @click.prevent="copyPublicKeyToClipboard({{ Js::from($private_key->public_key) }})">
                                Copy public key
                            </x-forms.button>
                            @if (data_get($server, 'privateKey.uuid') !== $private_key->uuid)
                                <x-forms.button canGate="update" :canResource="$server" class="w-full" wire:click='setPrivateKey({{ $private_key->id }})'>
                                    Use this key
                                </x-forms.button>
                            @else
                                <x-forms.button class="w-full" disabled>
                                    Currently used
                                </x-forms.button>
                            @endif
                        </div>
                    </div>
                @empty
                    <div>No private keys found. </div>
                @endforelse
            </div>
        </div>
    </div>

    @script
        <script>
            window.copyPublicKeyToClipboard = (publicKey) => {
                if (!publicKey) {
                    return;
                }

                if (!navigator.clipboard?.writeText) {
                    Livewire.dispatch('error', ['Failed to copy public key to clipboard.']);

                    return;
                }

                navigator.clipboard.writeText(publicKey).then(() => {
                    Livewire.dispatch('success', ['Public key copied to clipboard.']);
                }).catch(() => {
                    Livewire.dispatch('error', ['Failed to copy public key to clipboard.']);
                });
            };

            $wire.on('copyPublicKeyToClipboard', (event) => {
                const publicKey = event?.detail?.publicKey ?? event?.publicKey;
                window.copyPublicKeyToClipboard(publicKey);
            });
        </script>
    @endscript
</div>
