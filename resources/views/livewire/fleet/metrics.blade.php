<div class="flex flex-col gap-6">
    <div class="flex flex-col gap-1">
        <h1 class="text-2xl font-semibold text-black dark:text-fg">Metrics</h1>
        <p class="text-[13px] text-neutral-500 dark:text-fg-dim">Resource usage across your servers.</p>
    </div>

    @if ($servers->isEmpty())
        <x-empty title="No server metrics yet" description="Enable metrics on a server to see fleet resource usage here.">
            <x-slot:icon>
                <x-reicon name="cpu" class="size-8" />
            </x-slot:icon>
        </x-empty>
    @endif
</div>
