<div>
    <div class="flex items-end gap-2">
        <h1>Private Key</h1>
        <a href="{{ route('private-key.new') }}">
            <x-forms.button>Add a new Private Key</x-forms.button>
        </a>
    </div>
    <div class="pt-2 pb-8 text-sm">Selected Private Key for SSH connection</div>
    <div class="pb-10 text-sm">
        @if (data_get($server, 'privateKey.uuid'))
            Currently attached Private Key:
            <a href="{{ route('private-key.show', ['private_key_uuid' => data_get($server, 'privateKey.uuid')]) }}">
                <button class="text-white btn-link">{{ data_get($server, 'privateKey.name') }}</button>
            </a>
        @else
            <div class="text-sm">No private key attached.</div>
        @endif
    </div>
    <h3 class="pb-4">Select a different Private Key</h3>
    <div class="grid gap-2">
        @forelse ($privateKeys as $private_key)
            <x-forms.button wire:click='setPrivateKey({{ $private_key->id }})'>{{ $private_key->name }}
            </x-forms.button>
        @empty
            <div>No private keys found.
                <x-use-magic-bar />
            </div>
        @endforelse
    </div>
</div>
