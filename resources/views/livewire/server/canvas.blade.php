<div class="flex h-full flex-col">
    <x-slot:title>
        {{ $server ? $server->name.' > Canvas' : 'Servers > Canvas' }} | Coolify
    </x-slot>
    @if ($server)
        {{-- Status, proxy actions and the server name dock in the top bar. --}}
        <livewire:server.navbar :server="$server" />
        {{-- Its menu shows in the main sidebar on xl (components/server/sidebar.blade.php); below xl a canvas has no room for it. --}}
        <x-server.sidebar :server="$server" activeMenu="resources" />
    @endif
    <div data-environment-canvas
        data-endpoint="{{ $server ? '/servers/'.$server->uuid.'/canvas' : '/servers/canvas' }}"
        data-team="{{ $teamId }}" data-user="{{ auth()->id() }}"
        class="relative min-h-0 flex-1" wire:ignore>
        @if ($server)
            <x-canvas-view-toggle data-ec-views on-canvas :views="['list' => ['List view', 'unordered-list']]"
                :list-url="route('server.resources', ['server_uuid' => $server->uuid])"
                :canvas-url="route('server.canvas', ['server_uuid' => $server->uuid])" />
        @else
            {{-- The canvas moves this group into its toolbar; the page actions come last, at the right end. --}}
            <div data-ec-views class="flex items-center gap-2">
                <x-canvas-view-toggle on-canvas
                    :list-url="route('server.index')" :canvas-url="route('server.canvas.index')" />
                @can('createAnyResource')
                    <a href="{{ route('server.create') }}" {{ wireNavigate() }}
                        class="button button-highlighted whitespace-nowrap">
                        <x-reicon name="plus" class="size-3.5" />
                        New server
                    </a>
                @endcan
            </div>
        @endif
    </div>
</div>
