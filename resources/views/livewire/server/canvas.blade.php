<div class="flex h-full flex-col">
    <x-slot:title>
        {{ $server ? $server->name.' > Canvas' : 'Servers > Canvas' }} | Coolify
    </x-slot>
    @if ($server)
        {{-- Status, proxy actions and the server name dock in the top bar. --}}
        <livewire:server.navbar :server="$server" />
        {{-- Fixed rail on xl, hidden below xl (see .app-main-full .application-settings-navigation in app.css). --}}
        <x-server.sidebar :server="$server" activeMenu="resources" />
    @endif
    <div data-environment-canvas data-title="{{ $server ? 'Server canvas' : 'Servers canvas' }}"
        data-endpoint="{{ $server ? '/servers/'.$server->uuid.'/canvas' : '/servers/canvas' }}"
        data-team="{{ currentTeam()->id }}" data-user="{{ auth()->id() }}" @if ($server) data-toolbar-inline @endif
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
                @if (isDev())
                    @can('create', App\Models\Server::class)
                        <a href="{{ route('server.transfer.import') }}" {{ wireNavigate() }}
                            class="button whitespace-nowrap">
                            <x-reicon name="upload" class="size-3.5" />
                            Import server
                            <x-status-badge label="Dev" />
                        </a>
                    @endcan
                @endif
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
