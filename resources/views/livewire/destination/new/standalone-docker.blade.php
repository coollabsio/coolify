<div>
    <h1>Create a new Destination</h1>
    <div class="pb-5 text-sm breadcrumbs">
        <ul>
            <li>
                Destinations are used to separate resources in a server.
            </li>
        </ul>
    </div>
    <form class="flex flex-col gap-4" wire:submit.prevent='submit'>
        <div class="flex gap-2">
            <x-forms.input id="name" label="Name" required />
            <x-forms.input id="network" label="Network" required />
        </div>
        <x-forms.select id="server_id" label="Select a server" required>
            <option disabled>Select a server</option>
            @foreach ($servers as $server)
                <option value="{{ $server->id }}">{{ $server->name }}</option>
            @endforeach
        </x-forms.select>
        <x-forms.button type="submit">
            Save Destination
        </x-forms.button>
    </form>
</div>
