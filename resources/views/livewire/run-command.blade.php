<div>
    <form class="flex flex-col justify-center gap-2 xl:items-end xl:flex-row"
        wire:submit="$dispatchSelf('connectToContainer')">
        <x-forms.select label="Select Server or Container" id="server" required wire:model="selected_uuid">
            @foreach ($servers as $server)
                @if ($loop->first)
                    <option selected value="{{ $server->uuid }}">{{ $server->name }}</option>
                @else
                    <option value="{{ $server->uuid }}">{{ $server->name }}</option>
                @endif
                @foreach ($containers as $container)
                    @if ($container['server_uuid'] == $server->uuid)
                        <option value="{{ $container['uuid'] }}">
                            {{ $server->name }} -> {{ $container['name'] }}
                        </option>
                    @endif
                @endforeach
            @endforeach
        </x-forms.select>
        <x-forms.button type="submit">Start Connection</x-forms.button>
    </form>
    <livewire:project.shared.terminal />
</div>
