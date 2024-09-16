<div>
    <form class="flex flex-col gap-2 justify-center xl:items-end xl:flex-row"
        wire:submit="$dispatchSelf('connectToContainer')">
        <x-forms.select id="server" required wire:model.live="selected_uuid">
            @foreach ($servers as $server)
                @if ($loop->first)
                    <option disabled value="default">Select a server or container</option>
                @endif
                <option value="{{ $server->uuid }}">{{ $server->name }}</option>
                @foreach ($containers as $container)
                    @if ($container['server_uuid'] == $server->uuid)
                        <option value="{{ $container['uuid'] }}">
                            {{ $server->name }} -> {{ $container['name'] }}
                        </option>
                    @endif
                @endforeach
            @endforeach
        </x-forms.select>
        <x-forms.button type="submit">Connect</x-forms.button>
    </form>
    <livewire:project.shared.terminal />
</div>
