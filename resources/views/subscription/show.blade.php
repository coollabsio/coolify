<x-layout-subscription>
    @if ($settings->is_resale_license_active)
        <div class="flex justify-center mx-10">
            <div x-data>
                <div class="flex gap-2">
                    <h2>Subscription</h2>
                    <livewire:switch-team />
                </div>
                <div class="flex items-center pb-8">
                    <span>Currently active team: <span
                            class="text-warning">{{ session('currentTeam.name') }}</span></span>
                </div>
                @if(request()->query->get('cancelled'))
                <div class="text-xl text-center text-red-500">Something went wrong. Please try again.</div>
            @endif
                @if (config('subscription.provider') !== null)
                    <livewire:subscription.pricing-plans />
                @endif
            </div>
        </div>
    @else
        <div class="px-10">Resale license is not active. Please contact your instance admin.</div>
    @endif
</x-layout-subscription>
