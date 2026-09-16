<div class="flex flex-col gap-6">
    <div class="flex flex-col gap-1">
        <h1 class="text-2xl font-semibold text-black dark:text-fg">Metrics</h1>
        <p class="text-[13px] text-neutral-500 dark:text-fg-dim">Resource usage across your servers.</p>
    </div>

    <x-skeleton.tiles :count="6" grid="grid-cols-2 sm:grid-cols-3 lg:grid-cols-6"
        :labels="['Servers online', 'Fleet CPU', 'Fleet memory', 'Fleet disk', 'Fleet network', 'Containers']" />

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach (['CPU', 'Memory used'] as $label)
            <div class="rounded-lg bg-[var(--coollabs-elevated)] p-4 ring-1 ring-[var(--coollabs-hairline)]">
                <span class="text-[13px] font-medium text-black dark:text-fg">{{ $label }}</span>
                <x-skeleton class="mt-3 h-[220px] w-full rounded" />
            </div>
        @endforeach
    </div>
</div>
