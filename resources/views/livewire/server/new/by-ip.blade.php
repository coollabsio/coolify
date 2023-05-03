<div>
    <form class="flex flex-col" wire:submit.prevent='submit'>
        <x-inputs.input id="name" label="Name" required />
        <x-inputs.input id="description" label="Description" />
        <x-inputs.input id="ip" label="IP Address" required />
        <x-inputs.input id="user" label="User" />
        <x-inputs.input type="number" id="port" label="Port" />
        <x-inputs.input id="private_key_id" label="Private Key" required hidden />
        <x-inputs.button class="mt-4" type="submit">
            Submit
        </x-inputs.button>
    </form>
    <div class="flex gap-4">
        <div>
            <h1>Select a private key</h1>
            @foreach ($private_keys as $key)
                @if ($private_key_id == $key->id)
                    <x-inputs.button class="bg-blue-500" wire:click.defer="setPrivateKey('{{ $key->id }}')">
                        {{ $key->name }}</x-inputs.button>
                @else
                    <x-inputs.button wire:click.defer="setPrivateKey('{{ $key->id }}')">{{ $key->name }}
                    </x-inputs.button>
                @endif
            @endforeach
        </div>
        <div>
            <h2>Add a new One</h2>
            <livewire:private-key.create />
        </div>
    </div>
</div>
