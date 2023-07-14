<x-layout-subscription>
    @if (auth()->user()->isInstanceAdmin())
        <div class="pb-10">
            <h3>Resale License</h3>
            <livewire:check-license />
        </div>
    @endif
    @if (!auth()->user()->isInstanceAdmin() && !$settings->is_resale_license_active)
        <div>Resale license is not active. Please contact your instance admin.</div>
    @endif
    @if ($settings->is_resale_license_active)
        <div class="flex gap-2">
            <h3>Subscription</h3>
            <livewire:switch-team />
        </div>
        <div class="flex items-center pb-8">
            <span>Currently active team: {{ session('currentTeam.name') }}</span>
        </div>
        @if (data_get(auth()->user()->currentTeam(),
                'subscription'))
            <div>Status: {{ auth()->user()->currentTeam()->subscription->lemon_status }}</div>
            <div>Type: {{ auth()->user()->currentTeam()->subscription->lemon_variant_name }}</div>
            @if (auth()->user()->currentTeam()->subscription->lemon_status === 'cancelled')
                <div class="pb-4">Subscriptions ends at: {{ getEndDate() }}</div>
                <x-forms.button><a class="text-white" href="{{ getSubscriptionLink() }}">Subscribe
                        Again</a>
                </x-forms.button>
            @else
                <div class="pb-4">Renews at: {{ getRenewDate() }}</div>
            @endif
            <x-forms.button><a class="text-white" href="{{ getPaymentLink() }}">Update Payment Details</a>
            </x-forms.button>
        @else
            <x-forms.button class="mt-4"><a class="text-white" href="{{ getSubscriptionLink() }}">Subscribe Now</a>
            </x-forms.button>
        @endif
        <x-forms.button><a class="text-white" href="https://app.lemonsqueezy.com/my-orders">Manage My
                Subscription</a>
        </x-forms.button>
    @endif
</x-layout-subscription>
