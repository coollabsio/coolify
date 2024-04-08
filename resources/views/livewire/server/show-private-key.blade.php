<div>
    <div class="flex items-end gap-2 pb-6 ">
        <h2>Private Key</h2>
        <x-modal-input buttonTitle="+ Add" title="New Private Key">
            <livewire:security.private-key.create />
        </x-modal-input>
        <x-forms.button wire:click.prevent='checkConnection'>
            Check connection
        </x-forms.button>
    </div>

    <div class="flex flex-col gap-2 pb-6">
        @if (data_get($server, 'privateKey.uuid'))
            <div>
                Currently attached Private Key:
                <a
                    href="{{ route('security.private-key.show', ['private_key_uuid' => data_get($server, 'privateKey.uuid')]) }}">
                    <button class="dark:text-white btn-link">{{ data_get($server, 'privateKey.name') }}</button>
                </a>
            </div>
        @else
            <div class="">No private key attached.</div>
        @endif

    </div>
    <h3 class="pb-4">Choose another Key</h3>
    <div class="grid grid-cols-3 gap-2">
        @forelse ($privateKeys as $private_key)
            <div class="box group" wire:click='setPrivateKey({{ $private_key->id }})'>
                <div class="flex flex-col ">
                    <div class="box-title">{{ $private_key->name }}</div>
                    <div class="box-description">{{ $private_key->description }}</div>
                </div>
            </div>
        @empty
            <div>No private keys found. </div>
        @endforelse
    </div>
</div>
