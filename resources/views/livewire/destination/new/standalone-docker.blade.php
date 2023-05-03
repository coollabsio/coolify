<div>
    <form class="flex flex-col" wire:submit.prevent='submit'>
        <x-inputs.input id="name" label="Name" required />
        <x-inputs.input id="network" label="Network" required />
        <x-inputs.input id="server_id" label="Server ID" required />
        @foreach ($servers as $key)
            @if ($server_id == $key->id)
                <x-inputs.button class="bg-green-500" wire:click.prevent="setServerId('{{ $key->id }}')">
                    {{ $key->name }}
                </x-inputs.button>
            @else
                <x-inputs.button wire:click.prevent="setServerId('{{ $key->id }}')">{{ $key->name }}
                </x-inputs.button>
            @endif
        @endforeach
        <x-inputs.button class="mt-4" type="submit">
            Submit
        </x-inputs.button>
    </form>

</div>
