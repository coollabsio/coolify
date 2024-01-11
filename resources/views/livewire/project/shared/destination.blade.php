<div>
    <h2>Server</h2>
    <div class="">The destination server where your application will be deployed to.</div>
    <div class="py-4 ">
        <a class="box"
            href="{{ route('server.show', ['server_uuid' => data_get($resource, 'destination.server.uuid')]) }}">On
            server <span class="px-1 text-warning">{{ data_get($resource, 'destination.server.name') }}</span>
            in <span class="px-1 text-warning"> {{ data_get($resource, 'destination.network') }} </span> network.</a>
    </div>
    {{-- Additional Destinations:
    {{$resource->additional_destinations}} --}}
    {{-- @if (count($servers) > 0)
        <div>
            <h3>Additional Servers</h3>
            @foreach ($servers as $server)
                <form wire:submit='submit' class="p-2 border border-coolgray-400">
                    <h4>{{ $server->name }}</h4>
                    <div class="text-sm text-coolgray-600">{{ $server->description }}</div>
                    <x-forms.checkbox id="additionalServers.{{ $loop->index }}.enabled" label="Enabled">
                    </x-forms.checkbox>
                    <x-forms.select label="Destination" id="additionalServers.{{ $loop->index }}.destination" required>
                        @foreach ($server->destinations() as $destination)
                            @if ($loop->first)
                                <option selected value="{{ $destination->uuid }}">{{ $destination->name }}</option>
                                <option value="{{ $destination->uuid }}">{{ $destination->name }}</option>
                            @else
                                <option value="{{ $destination->uuid }}">{{ $destination->name }}</option>
                                <option value="{{ $destination->uuid }}">{{ $destination->name }}</option>
                            @endif
                        @endforeach
                    </x-forms.select>
                    <x-forms.button type="submit">Save</x-forms.button>
                </form>
            @endforeach
        </div>
    @endif --}}
</div>
