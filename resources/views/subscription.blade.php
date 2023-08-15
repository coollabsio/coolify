<x-layout-subscription>
    @if ($settings->is_resale_license_active)
        <div class="flex justify-center mx-10">
            <div>
                <div class="flex gap-2">
                    <h2>Subscription</h2>
                    <livewire:switch-team />
                </div>
                <div class="flex items-center pb-8">
                    <span>Currently active team: <span
                            class="text-warning">{{ session('currentTeam.name') }}</span></span>
                </div>
                <x-pricing-plans />
            </div>
        </div>
    @else
        <div class="px-10">Resale license is not active. Please contact your instance admin.</div>
    @endif
</x-layout-subscription>
