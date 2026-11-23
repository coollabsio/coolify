<div class="w-full">
    <x-slot:title>
        Docker Registries | Coolify
    </x-slot>

    <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex min-w-0 flex-col gap-1">
            <h1 class="min-w-0 text-[24px]! leading-7! font-semibold! tracking-tight!">Docker Registries</h1>
            <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                Registry logins on each server, read from the Docker config that deployments use. Use <b>Multi-server login</b>,
                or run <code>docker login &lt;registry&gt;</code> on a server.
            </p>
        </div>
        @if ($servers->isNotEmpty())
            <x-modal-input buttonTitle="Multi-server login" title="Log in on multiple servers" isHighlightedButton
                subtitle="Runs docker login on each server that you choose.">
                <livewire:server.docker-registries.login key="registry-login-overview" />
            </x-modal-input>
        @endif
    </div>

    @if ($servers->isEmpty())
        <x-empty title="No servers yet"
            description="Add a server to manage its Docker registry logins."
            icon-name="servers" />
    @else
        <div class="flex flex-col gap-4">
            @foreach ($servers as $server)
                <livewire:server.docker-registries.server-registries :server="$server"
                    :key="'docker-registries-'.$server->uuid" />
            @endforeach
        </div>
    @endif
</div>
