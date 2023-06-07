<div class="grid grid-cols-2">
    @forelse ($private_keys as $private_key)
        <x-forms.button wire:click='setPrivateKey({{ $private_key->id }})'>{{ $private_key->name }}
        </x-forms.button>
    @empty
        <div>No private keys found.
            <x-use-magic-bar />
        </div>
    @endforelse
</div>
