<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Upload | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />

    <div class="flex h-full flex-col gap-4">
        <div>
            <h2>Upload</h2>
            <div>Upload a file to this server or one of its running containers.</div>
        </div>

        @if (! $server->isTerminalEnabled())
            <div>Terminal access is disabled on this server.</div>
        @elseif (! $server->isFunctional())
            <div>Server is not functional. Validate it before uploading files.</div>
        @else
            <livewire:terminal.file-import
                :containers="$containers"
                :selectedUuid="$server->uuid"
                :servers="$servers"
                :key="'server-upload-'.$server->uuid" />
        @endif
    </div>
</div>
