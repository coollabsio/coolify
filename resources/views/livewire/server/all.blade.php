<div>
    <div class="flex items-start gap-2">
        <h1>Servers</h1>
        <a  class="text-white hover:no-underline" href="{{ route('server.create') }}">
            <x-forms.button class="btn">+ Add</x-forms.button>
        </a>
    </div>
    <div class="subtitle ">All Servers</div>
    <div class="grid gap-2 lg:grid-cols-2">
        @forelse ($servers as $server)
            <a  href="{{ route('server.show', ['server_uuid' => data_get($server, 'uuid')]) }}"
                @class([
                    'gap-2 border cursor-pointer box group',
                    'border-transparent' => $server->settings->is_reachable,
                    'border-red-500' => !$server->settings->is_reachable,
                ])>
                <div class="flex flex-col mx-6">
                    <div class="font-bold text-white">
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
                    </div>
                </div>
                <div class="flex-1"></div>
            </a>
        @empty
            <div>
                <div>No servers found. Without a server, you won't be able to do much.</div>
                <x-use-magic-bar link="/server/new" />
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
