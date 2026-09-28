<div class="w-full">
    <x-slot:title>
        Docker Registries | Coolify
    </x-slot>

    <div class="mb-5 flex flex-col gap-1">
        <h1 class="min-w-0 text-[24px]! leading-7! font-semibold! tracking-tight!">Docker Registries</h1>
        <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
            Registry logins on each server, read from the Docker config that deployments use. Use <b>Log in</b> on a
            server, or run <code>docker login &lt;registry&gt;</code> on it.
        </p>
    </div>

    @if ($servers->isEmpty())
        <x-empty title="No servers to show"
            description="Only team admins can see registry logins, and only for servers of the current team."
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
