<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Private Key | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-8 grid w-full max-w-[1180px] min-w-0 gap-8 xl:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
        <x-server.sidebar :server="$server" activeMenu="private-key" />

        <div class="application-settings-form flex w-full flex-col gap-6">
            <x-application.settings-section id="server-private-keys-section" title="Private key"
                helper="Choose the SSH key Coolify uses to connect to this server." flush>
                <x-slot:actions>
                    <div class="flex items-center gap-2">
                        <x-forms.button canGate="update" :canResource="$server"
                            wire:click.prevent="checkConnection">
                            <x-reicon name="refresh" class="size-3.5" />
                            Check connection
                        </x-forms.button>

                        @can('createAnyResource')
                            <div x-data="{ open: false }" class="relative" @click.outside="open = false">
                                <x-forms.button isHighlighted type="button" @click="open = !open">
                                    <x-reicon name="plus" class="size-3.5" />
                                    Add
                                </x-forms.button>
                                <div x-show="open" x-cloak class="listbox-panel right-0 left-auto min-w-52"
                                    role="menu">
                                    <button type="button" class="listbox-option"
                                        wire:click="generatePrivateKey('ed25519')" @click="open = false">
                                        Generate ED25519
                                    </button>
                                    <button type="button" class="listbox-option"
                                        wire:click="generatePrivateKey('rsa')" @click="open = false">
                                        Generate RSA
                                    </button>
                                    <x-modal-input title="Add Private Key Manually">
                                        <x-slot:content>
                                            <button type="button" class="listbox-option w-full"
                                                @click="open = false">
                                                Add manually
                                            </button>
                                        </x-slot:content>
                                        <livewire:security.private-key.create />
                                    </x-modal-input>
                                </div>
                            </div>
                        @endcan
                    </div>
                </x-slot:actions>

                @forelse ($privateKeys as $privateKey)
                    <div
                        class="flex flex-col gap-4 border-b border-neutral-200 px-4 py-4 last:border-b-0 sm:flex-row sm:items-center sm:justify-between dark:border-white/[0.08]">
                        <div class="flex min-w-0 items-start gap-3">
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 dark:bg-white/[0.06] dark:text-fg-dim">
                                <x-reicon name="keys" class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="truncate text-sm font-medium text-neutral-950 dark:text-fg">
                                        {{ $privateKey->name }}
                                    </p>
                                    @if (data_get($server, 'privateKey.uuid') === $privateKey->uuid)
                                        <x-status-badge status="Active" type="success" />
                                    @endif
                                </div>
                                <p class="mt-1 text-xs text-neutral-500 dark:text-fg-dim">
                                    {{ $privateKey->description ?: 'No description provided.' }}
                                </p>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <x-forms.button
                                @click.prevent="copyPublicKeyToClipboard({{ Js::from($privateKey->public_key) }})">
                                Copy public key
                            </x-forms.button>
                            @if (data_get($server, 'privateKey.uuid') !== $privateKey->uuid)
                                <x-forms.button canGate="update" :canResource="$server"
                                    wire:click="setPrivateKey({{ $privateKey->id }})">
                                    Use this key
                                </x-forms.button>
                            @endif
                        </div>
                    </div>
                @empty
                    <x-empty size="sm" title="No private keys"
                        description="Add or generate a private key to connect to this server.">
                        <x-slot:icon>
                            <x-reicon name="keys" class="size-8" />
                        </x-slot:icon>
                    </x-empty>
                @endforelse
            </x-application.settings-section>
        </div>
    </div>

    @script
        <script>
            window.copyPublicKeyToClipboard = publicKey => {
                if (!publicKey || !navigator.clipboard?.writeText) {
                    Livewire.dispatch('error', ['Failed to copy public key to clipboard.']);
                    return;
                }

                navigator.clipboard.writeText(publicKey)
                    .then(() => Livewire.dispatch('success', ['Public key copied to clipboard.']))
                    .catch(() => Livewire.dispatch('error', ['Failed to copy public key to clipboard.']));
            };

            $wire.on('copyPublicKeyToClipboard', event => {
                window.copyPublicKeyToClipboard(event?.detail?.publicKey ?? event?.publicKey);
            });
        </script>
    @endscript
</div>
