<div class="flex w-full min-w-0 flex-col gap-6">
    {{-- Header (real chrome; only the data below is a skeleton) --}}
    <div class="flex flex-col gap-4">
        <div class="min-w-0">
            <h1 class="min-w-0 text-[24px]! leading-7! font-semibold! tracking-tight!">Metrics</h1>
            <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                Resource usage across every server, reported by Sentinel.
            </p>
        </div>

        {{-- Filter bar --}}
        <div class="flex flex-wrap items-center gap-2">
            <x-skeleton class="h-9 w-full rounded-lg sm:w-52" />
            <x-skeleton class="h-9 w-52 rounded-lg sm:ml-auto" />
        </div>
    </div>

    <x-application.settings-section id="metrics-overview-section" title="Overview">
        <x-skeleton.tiles :count="6" grid="grid-cols-2 sm:grid-cols-3 lg:grid-cols-6"
            :labels="['Servers online', 'Fleet CPU', 'Fleet memory', 'Fleet disk', 'Fleet network', 'Containers']" />
    </x-application.settings-section>

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach (['CPU', 'Memory used', 'Network throughput', 'Load average', 'Disk usage'] as $label)
            <x-application.settings-section :title="$label" @class(['lg:col-span-2' => $label === 'CPU'])>
                <x-skeleton class="h-[240px] w-full rounded-lg" />
            </x-application.settings-section>
        @endforeach
    </div>

    <x-application.settings-section title="Servers" flush>
        <x-skeleton.table :rows="4" flush />
    </x-application.settings-section>

    <x-application.settings-section title="Hottest containers" flush>
        <x-skeleton.table :rows="6" flush />
    </x-application.settings-section>
</div>
