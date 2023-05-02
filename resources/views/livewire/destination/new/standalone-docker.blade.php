<div>
    <form class="flex flex-col" wire:submit.prevent='submit'>
        <x-form-input id="name" label="Name" required />
        <x-form-input id="network" label="Network" required />
        <x-form-input id="server_id" label="Server ID" required />
        @foreach ($servers as $key)
            <button @if ($server_id == $key->id) class="bg-green-500" @endif
                wire:click.prevent="setServerId('{{ $key->id }}')">{{ $key->name }}</button>
        @endforeach
        <button class="mt-4" type="submit">
            Submit
        </button>
    </form>

</div>
