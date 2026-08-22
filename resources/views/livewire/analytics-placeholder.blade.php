<div class="flex w-full min-w-0 flex-col gap-6">
    {{-- Header (real chrome; only the data below is a skeleton) --}}
    <div class="flex flex-col gap-4">
        <div class="flex items-center gap-3">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">
                <x-reicon name="analytics" class="size-5" />
            </span>
            <div class="min-w-0">
                <h1 class="text-[16px]! leading-5! font-semibold! text-black dark:text-fg">Analytics</h1>
                <p class="mt-0.5 text-[12px] text-neutral-500 dark:text-fg-faint">
                    Request traffic across every application and server, reported by Sentinel.
                </p>
            </div>
        </div>

        {{-- Filter bar --}}
        <div class="flex flex-wrap items-center gap-2">
            <x-skeleton class="h-9 w-full rounded-lg sm:w-52" />
            <x-skeleton class="h-9 w-full rounded-lg sm:w-52" />
            <x-skeleton class="h-9 w-44 rounded-lg sm:ml-auto" />
        </div>
    </div>

    <x-application.settings-section id="analytics-overview-section" title="Overview">
        <x-skeleton.tiles :count="5" />
    </x-application.settings-section>

    <x-application.settings-section id="analytics-requests-section" title="Requests">
        <x-skeleton class="h-[220px] w-full rounded-lg" />
    </x-application.settings-section>

    <x-application.settings-section id="analytics-status-codes-section" title="Status codes">
        <x-skeleton class="h-9 w-full rounded-lg" />
        <div class="mt-3 flex flex-wrap gap-2">
            <x-skeleton class="h-4 w-20 rounded-full" />
            <x-skeleton class="h-4 w-16 rounded-full" />
            <x-skeleton class="h-4 w-16 rounded-full" />
            <x-skeleton class="h-4 w-14 rounded-full" />
        </div>
    </x-application.settings-section>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-application.settings-section id="analytics-hosts-section" title="Top hosts" flush>
            <x-skeleton.table :rows="6" flush />
        </x-application.settings-section>
        <x-application.settings-section id="analytics-apps-section" title="Top applications" flush>
            <x-skeleton.table :rows="6" flush />
        </x-application.settings-section>
    </div>

    <x-application.settings-section id="analytics-paths-section" title="Top paths" flush>
        <x-skeleton.table :rows="6" flush />
    </x-application.settings-section>

    <x-application.settings-section id="analytics-country-section" title="Countries" flush>
        <x-skeleton class="h-64 w-full" />
    </x-application.settings-section>
</div>
