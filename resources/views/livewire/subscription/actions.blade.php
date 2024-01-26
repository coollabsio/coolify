<div>
    @if (subscriptionProvider() === 'stripe')
        <x-forms.button wire:click='stripeCustomerPortal'>Manage My Subscription</x-forms.button>
        <div class="pt-4">
            <div>Current Plan: <span class="text-warning">{{ data_get(currentTeam(), 'subscription')->type() }}<span>
            </div>
            @if (currentTeam()->subscription->stripe_cancel_at_period_end)
                <div>Subscription is active but on cancel period.</div>
            @else
                <div>Subscription is active. Last invoice is
                    {{ currentTeam()->subscription->stripe_invoice_paid ? 'paid' : 'not paid' }}.</div>
            @endif

            @if (currentTeam()->subscription->stripe_cancel_at_period_end)
                <a class="hover:no-underline" href="{{ route('subscription.index') }}"><x-forms.button>Subscribe
                        again</x-forms.button></a>
            @endif
            <div>To update your subscription (upgrade / downgrade), please <a class="text-white underline"
                    href="{{ config('coolify.contact') }}" target="_blank">contact us.</a></div>
        </div>
    @endif
    @if (subscriptionProvider() === 'lemon')
        <div>Status: {{ currentTeam()->subscription->lemon_status }}</div>
        <div>Type: {{ currentTeam()->subscription->lemon_variant_name }}</div>
        @if (currentTeam()->subscription->lemon_status === 'cancelled')
            <div class="pb-4">Subscriptions ends at: {{ getRenewDate() }}</div>
            <div class="py-4">If you would like to change the subscription to a lower/higher plan, <a
                    class="text-white underline" href="{{ config('coolify.contact') }}" target="_blank">please
                    contact
                    us.</a></div>
        @else
            <div class="pb-4">Renews at: {{ getRenewDate() }}</div>
        @endif
        <div class="flex flex-col gap-2">
            <div class="flex gap-2">
                @if (currentTeam()->subscription->lemon_status === 'cancelled')
                    <x-forms.button class="bg-coollabs-gradient" wire:click='resume'>Resume Subscription
                    </x-forms.button>
                @else
                    <x-forms.button wire:click='cancel'>Cancel Subscription</x-forms.button>
                @endif
            </div>
            <div>
                <x-forms.button><a class="text-white hover:no-underline" href="{{ getPaymentLink() }}">Update Payment
                        Details</a>
                </x-forms.button>
                <a class="text-white hover:no-underline"
                    href="https://app.lemonsqueezy.com/my-orders"><x-forms.button>Manage My
                        Subscription</x-forms.button></a>
            </div>
        </div>
    @endif

</div>
