<div>
    <form class="flex items-end gap-4" wire:submit.prevent='submit'>
        <x-inputs.input id="name" label="Name" required />
        <x-inputs.input id="network" label="Network" required />
        <x-inputs.select id="server_id" label="Select a server" required>
            @foreach ($servers as $server)
                <option disabled>Select a server</option>
                <option value="{{ $server->id }}">{{ $server->name }}</option>
            @endforeach
        </x-inputs.select>
        <x-inputs.button isBold type="submit">
            Submit
        </x-inputs.button>
    </form>
</div>
