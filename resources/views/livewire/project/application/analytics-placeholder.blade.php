<div class="flex flex-col gap-6">
    {{-- Real section chrome around skeleton bodies, so the tab has no layout jump on load. --}}
    <x-application.settings-section id="analytics-range-section" title="Analytics">
        <x-slot:actions>
            <x-skeleton class="h-8 w-48 rounded-lg" />
        </x-slot:actions>
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

    <x-application.settings-section id="analytics-paths-section" title="Top paths" flush>
        <x-skeleton.table :rows="6" flush />
    </x-application.settings-section>

    <x-application.settings-section id="analytics-country-section" title="Countries" flush>
        <x-skeleton class="h-64 w-full" />
    </x-application.settings-section>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-application.settings-section id="analytics-device-section" title="Requests by device type">
            <div class="flex items-center justify-center py-4">
                <x-skeleton class="size-40 rounded-full" />
            </div>
        </x-application.settings-section>
        <x-application.settings-section id="analytics-protocol-section" title="Top HTTP versions">
            <x-skeleton.table :rows="5" />
        </x-application.settings-section>
    </div>
</div>
