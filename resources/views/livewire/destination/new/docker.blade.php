<div class="w-full ">
    <div class="subtitle">Destinations are used to segregate resources by network.</div>
    <form class="flex flex-col gap-4" wire:submit='submit'>
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
            Continue
        </x-forms.button>
    </form>
</div>
