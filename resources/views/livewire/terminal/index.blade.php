<div>
    <x-slot:title>
        Terminal | Coolify
    </x-slot>
    <h1>Terminal</h1>
    <div class="flex gap-2 items-end subtitle">
        <div>Execute commands on your servers and containers without leaving the browser.</div>
        <x-helper
            helper="If you're having trouble connecting to your server, make sure that the port is open.<br><br><a class='underline' href='https://coolify.io/docs/knowledge-base/server/firewall/#terminal' target='_blank'>Documentation</a>"></x-helper>
    </div>
    <div x-init="$wire.loadContainers()">
        @if ($isLoadingContainers)
            <div class="pt-1">
                <x-loading text="Loading servers and containers..." />
            </div>
        @else
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
        @endif
        <livewire:project.shared.terminal />
    </div>
</div>
