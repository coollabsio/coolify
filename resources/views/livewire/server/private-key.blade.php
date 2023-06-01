<div class="flex flex-wrap gap-2">
    @forelse ($private_keys as $private_key)
        <div class="w-64 box">
            <button wire:click='setPrivateKey({{ $private_key->id }})'>{{ $private_key->name }}
            </button>
        </div>
    @empty
        <p>No private keys found</p>
    @endforelse
</div>
