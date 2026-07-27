<div class="application-settings-form w-full">
    <x-slot:title>
        Destinations | Coolify
    </x-slot>

    <header class="mb-5 flex items-center justify-between gap-4">
        <h1 class="text-[24px]! leading-7! font-semibold! tracking-tight!">Destinations</h1>
        <div class="shrink-0">
            @if ($servers->count() > 0)
                @can('createAnyResource')
                    <x-modal-input title="New Destination">
                        <x-slot:content>
                            <button type="button"
                                class="button bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!">
                                <x-reicon name="plus" class="size-3.5" />
                                New destination
                            </button>
                        </x-slot:content>
                        <livewire:destination.new.docker />
                    </x-modal-input>
                @endcan
            @endif
        </div>
    </header>

    <p class="mb-4 text-[11px] text-neutral-500 dark:text-fg-faint">
        {{ $destinations->count() }} {{ Str::plural('network endpoint', $destinations->count()) }}
    </p>

    @if ($destinations->isEmpty())
        <div
            class="flex min-h-80 flex-col items-center justify-center rounded-xl border border-dashed border-neutral-300 bg-neutral-50 px-6 text-center dark:border-white/[0.1] dark:bg-white/[0.02]">
            <div
                class="mb-4 flex size-11 items-center justify-center rounded-xl border border-neutral-200 bg-white text-neutral-400 shadow-sm dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-faint">
                <x-reicon name="destinations" class="size-5" />
            </div>
            <h2 class="text-[15px] font-semibold">No destinations yet</h2>
            <p class="mt-1 max-w-sm text-[13px] text-neutral-500 dark:text-fg-dim">
                Add a Docker network endpoint to choose where your resources are deployed.
            </p>
        </div>
    @else
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($destinations as $destination)
                <a class="group flex min-h-28 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:no-underline hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]"
                    {{ wireNavigate() }}
                    href="{{ route('destination.show', ['destination_uuid' => data_get($destination, 'uuid')]) }}">
                    <div class="flex items-start gap-3">
                        <div
                            class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                            <x-reicon name="destinations" class="size-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">
                                {{ $destination->name }}
                            </h2>
                            <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                {{ $destination->server->name }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-auto flex items-center gap-2 pt-4">
                        @if ($destination->getMorphClass() === 'App\Models\SwarmDocker')
                            <x-status-badge label="Docker Swarm" type="warning" />
                        @else
                            <x-status-badge label="Standalone Docker" type="success" />
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
