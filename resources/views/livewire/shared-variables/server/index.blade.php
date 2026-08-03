<div>
    <x-slot:title>
        Server Variables | Coolify
    </x-slot>

    <x-dashboard.navbar section="shared-variables" title="Shared variables"
        subtitle="Server-wide variables available to resources on a server" />

    <div class="w-full">
    @if ($servers->isEmpty())
        <x-empty title="No servers yet"
            description="Add a server before creating server-wide variables."
            icon-name="servers" />
    @else
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($servers as $server)
                <a class="group flex min-h-28 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:no-underline hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]"
                    href="{{ route('shared-variables.server.show', ['server_uuid' => $server->uuid]) }}"
                    {{ wireNavigate() }}>
                    <div class="flex items-start gap-3">
                        <div
                            class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                            <x-reicon name="servers" class="size-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">
                                {{ $server->name }}
                            </h2>
                            <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                {{ $server->description ?: $server->ip }}
                            </p>
                        </div>
                    </div>
                    <div class="mt-auto flex items-center justify-between pt-4">
                        <x-status-badge :status="$server->isFunctional() ? 'Ready' : 'Validation required'"
                            :type="$server->isFunctional() ? 'success' : 'warning'" />
                        <x-reicon name="arrow-right"
                            class="size-3.5 text-neutral-400 transition-transform group-hover:translate-x-0.5 dark:text-fg-faint" />
                    </div>
                </a>
            @endforeach
        </div>
    @endif
    </div>
</div>
