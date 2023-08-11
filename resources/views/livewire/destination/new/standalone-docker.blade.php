<div>
    <h1>Create a new Destination</h1>
    <div class="pt-2 pb-10 ">Destinations are used to segregate resources by network.</div>
    <form class="flex flex-col gap-4" wire:submit.prevent='submit'>
        <div class="flex gap-2">
            <x-forms.input id="name" label="Name" required />
            <x-forms.input id="network" label="Network" required />
        </div>
        <x-forms.select id="server_id" label="Select a server" required wire:change="generate_name">
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
