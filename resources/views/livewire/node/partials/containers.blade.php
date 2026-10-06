<x-application.settings-section id="node-containers-section" title="Containers" flush
    helper="Read-only Podman inventory that Sentinel reports. Managed containers belong to Coolify workloads; external containers were started outside Coolify.">
    <x-slot:actions>
        <x-forms.button type="button" wire:click="refreshContainers" title="Refresh container inventory">
            <x-reicon name="refresh" class="size-3.5" />
            Refresh
        </x-forms.button>
    </x-slot:actions>

    @if ($node->containers->isEmpty())
        <div class="p-4">
            <x-empty size="sm" title="No containers found"
                description="Refresh the inventory to read containers from Podman." icon-name="grid" />
        </div>
    @else
        <div class="overflow-x-auto">
            <div
                class="grid min-w-[680px] grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)_7rem_8rem] border-b border-neutral-200 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:text-fg-faint">
                <div>Container</div>
                <div>Image</div>
                <div>State</div>
                <div>Ownership</div>
            </div>
            @foreach ($node->containers->sortBy('name') as $container)
                <div wire:key="node-container-{{ $container->uuid }}"
                    class="grid min-h-12 min-w-[680px] grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)_7rem_8rem] items-center border-b border-neutral-200 px-4 py-2.5 text-[12px] last:border-b-0 dark:border-white/[0.07]">
                    <div class="min-w-0">
                        <p class="truncate font-medium text-black dark:text-fg">{{ $container->name }}</p>
                        <p class="truncate font-mono text-[10px] text-neutral-500 dark:text-fg-faint">
                            {{ str($container->runtime_id)->limit(20) }}
                        </p>
                    </div>
                    <p class="truncate font-mono text-[11px] text-neutral-600 dark:text-fg-dim">{{ $container->image }}</p>
                    <div class="justify-self-start">
                        <x-status-badge :status="str($container->state)->title()"
                            :type="$container->state === 'running' ? 'success' : 'warning'" />
                    </div>
                    <div class="justify-self-start">
                        <x-status-badge :status="str($container->management_state->value)->title()"
                            :type="match ($container->management_state->value) {
                                'managed' => 'success',
                                'unrecognized' => 'warning',
                                default => 'neutral',
                            }" />
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-application.settings-section>
