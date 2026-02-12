<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Private Key | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="private-key" />
        <div class="w-full">
            <div class="form-section-title mb-6">
                <h2>Private Key</h2>
                <div class="flex items-center gap-2">
                    @can('createAnyResource')
                        <x-modal-input buttonTitle="+ Add" title="New Private Key">
                            <livewire:security.private-key.create />
                        </x-modal-input>
                    @endcan
                    <x-forms.button canGate="update" :canResource="$server" isHighlighted wire:click.prevent='checkConnection'>
                        Check connection
                    </x-forms.button>
                </div>
            </div>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 -mt-4 mb-4">Change your server's private key.</p>
            <div class="grid xl:grid-cols-2 grid-cols-1 gap-2">
                @forelse ($privateKeys as $private_key)
                    <div
                        class="box-without-bg justify-between dark:bg-coolgray-100 bg-white items-center flex flex-col gap-4">
                        <div class="flex flex-col w-full">
                            <div class="box-title">{{ $private_key->name }}</div>
                            <div class="box-description">{{ $private_key->description }}</div>
                        </div>
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
                @empty
                    <div class="empty-state">No private keys found.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
