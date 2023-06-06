<div>
    <form class="flex flex-col gap-4" wire:submit.prevent='submit'>
        <div class="flex gap-2">
            <h1>New Destination</h1>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
        </div>
        <x-forms.input id="name" label="Name" required />
        <x-forms.input id="network" label="Network" required />
        <x-forms.select id="server_id" label="Select a server" required>
            <option disabled>Select a server</option>
            @foreach ($servers as $server)
                <option value="{{ $server->id }}">{{ $server->name }}</option>
            @endforeach
        </x-forms.select>

    </form>
</div>
