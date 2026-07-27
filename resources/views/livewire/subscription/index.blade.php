<div class="application-settings-form w-full max-w-[1180px]">
    <x-slot:title>
        Subscribe | Coolify
    </x-slot>

    <x-dashboard.navbar section="subscription" />

    @if (auth()->user()->isAdminFromSession())
        @if ($loading)
            <div class="flex min-h-80 items-center justify-center" wire:init="getStripeStatus">
                <x-loading text="Loading your subscription status..." />
            </div>
        @else
            @if ($isUnpaid)
                <x-application.settings-section title="Payment failed"
                    description="Your latest Coolify Cloud payment could not be processed.">
                    <x-callout type="danger" title="Subscription payment is past due">
                        Update the payment method or settle the outstanding invoice in the billing portal.
                    </x-callout>
                    <div class="mt-4">
                        <x-forms.button wire:click="stripeCustomerPortal" isHighlighted>Open billing
                            portal</x-forms.button>
                    </div>
                </x-application.settings-section>
            @else
                @if (config('subscription.provider') === 'stripe')
                    @if ($isCancelled)
                        <x-callout type="warning" title="No active subscription" class="mb-6">
                            Choose a plan to continue using Coolify Cloud.
                        </x-callout>
                    @endif
                    <livewire:subscription.pricing-plans />
                @endif
            @endif
        @endif
    @else
        <x-application.settings-section title="Subscription"
            description="Only team administrators can manage billing and plan limits.">
            <x-callout type="danger" title="Insufficient Permissions">
                You are not an admin so you cannot manage your Team's subscription. If this does not make sense, please
                <span class="underline cursor-pointer dark:text-white" wire:click="help">contact us</span>.
            </x-callout>
        </x-application.settings-section>
    @endif
</div>
