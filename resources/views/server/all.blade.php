<x-layout>
    <h1>Servers</span></h1>
    <div class="pt-2 pb-10 ">All Servers</div>
    <div class="grid gap-2 lg:grid-cols-2">
        @forelse ($servers as $server)
            <div x-data x-on:click="goto('{{ $server->uuid }}')" @class([
                'gap-2 border cursor-pointer box group',
                'border-transparent' => $server->settings->is_reachable,
                'border-red-500' => !$server->settings->is_reachable,
            ])>
                <div class="flex flex-col mx-6">
                    <div class=" group-hover:text-white">
                        {{ $server->name }}
                    </div>
                    <div class="text-xs group-hover:text-white"
                         href="{{ route('server.show', ['server_uuid' => data_get($server, 'uuid')]) }}">
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
                    </div>
                </div>
                <div class="flex-1"></div>
            </div>
        @empty
            <div>
                <div>No servers found. Without a server, you won't be able to do much.</div>
                <x-use-magic-bar link="/server/new"/>
            </div>
        @endforelse
        <script>
            function goto(uuid) {
                window.location.href = '/server/' + uuid;
            }
        </script>
    </div>
</x-layout>
