<div>
    <x-slot:title>
        Servers | Coolify
    </x-slot>
    <div class="flex items-start gap-2">
        <h1>Servers</h1>
        <x-modal-input buttonTitle="+ Add" title="New Server" :closeOutside="false">
            <livewire:server.create />
        </x-modal-input>
    </div>
    <div class="subtitle">All your servers are here.</div>
    <div class="grid gap-2 lg:grid-cols-2">
        @forelse ($servers as $server)
            <a href="{{ route('server.show', ['server_uuid' => data_get($server, 'uuid')]) }}"
                @class([
                    'gap-2 border cursor-pointer box group',
                    'border-transparent' =>
                        $server->settings->is_reachable &&
                        $server->settings->is_usable &&
                        !$server->settings->force_disabled,
                    'border-red-500' =>
                        !$server->settings->is_reachable || $server->settings->force_disabled,
                ])>
                <div class="flex flex-col justify-center mx-6">
                    <div class="font-bold dark:text-white">
                        {{ $server->name }}
                    </div>
                    <div class="description">
                        {{ $server->description }}</div>
                    <div class="flex gap-1 text-xs text-error">
                        @if (!$server->settings->is_reachable)
                            <span>Not reachable</span>
                        @endif
                        @if (!$server->settings->is_reachable && !$server->settings->is_usable)
                            &
                        @endif
                        @if (!$server->settings->is_usable)
                            <span>Not usable by Coolify</span>
                        @endif
                        @if ($server->settings->force_disabled)
                            <span>Disabled by the system</span>
                        @endif
                    </div>
                </div>
                <div class="flex-1"></div>
            </a>
        @empty
            <div>
                <div>No servers found. Without a server, you won't be able to do much.</div>
            </div>
        @endforelse
        @isset($error)
            <div class="text-center text-error">
                <span>{{ $error }}</span>
            </div>
        @endisset
        <script>
            function goto(uuid) {
                window.location.href = '/server/' + uuid;
            }
        </script>
    </div>
</div>
