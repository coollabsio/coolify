<div>
    <x-slot:title>
        Command Center | Coolify
    </x-slot>
    <h1>Command Center</h1>
    <div class="subtitle">Execute commands on your servers without leaving the browser.</div>
    @if ($servers->count() > 0)
        <livewire:run-command :servers="$servers" />
    @else
        <div>
            <div>No servers found. Without a server, you won't be able to do much.</div>
        </div>
    @endif
</div>
