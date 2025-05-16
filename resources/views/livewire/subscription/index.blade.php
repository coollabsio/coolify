<div>
    <x-slot:title>
        Subscribe | Coolify
    </x-slot>
    @if (auth()->user()->isAdminFromSession())
        @if (request()->query->get('cancelled'))
            <div class="mb-6 rounded-sm alert-error">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 stroke-current shrink-0" fill="none"
                    viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>Something went wrong with your subscription. Please try again or contact
                    support.</span>
            </div>
        @endif
        <div class="flex gap-2">
            <h1>Subscriptions</h1>
        </div>
        @if ($loading)
            <div class="flex gap-2" wire:init="getStripeStatus">
                Loading your subscription status...
            </div>
        @else
            @if ($isUnpaid)
                <div class="mb-6 rounded-sm alert-error">
                    <span>Your last payment was failed for Coolify Cloud.</span>
                </div>
                <div>
                    <p class="mb-2">Open the following link, navigate to the button and pay your unpaid/past due
                        subscription.
                    </p>
                    <x-forms.button wire:click='stripeCustomerPortal'>Billing Portal</x-forms.button>
                </div>
            @else
                @if (config('subscription.provider') === 'stripe')
                    <div @class([
                        'pb-4' => $isCancelled,
                        'pb-10' => !$isCancelled,
                    ])>
                        @if ($isCancelled)
                            <div class="alert-error">
                                <span>It looks like your previous subscription has been cancelled, because you forgot to
                                    pay
                                    the bills.<br />Please subscribe again to continue using Coolify.</span>
                            </div>
                        @endif
                    </div>
                    <livewire:subscription.pricing-plans />
                @endif
            @endif
        @endif
    @else
        <div class="flex flex-col justify-center mx-10">
            <div class="flex gap-2">
                <h1>Subscription</h1>
            </div>
            <div>You are not an admin so you cannot manage your Team's subscription. If this does not make sense, please
                <span class="underline cursor-pointer dark:text-white" wire:click="help">contact
                    us</span>.
            </div>
        </div>
    @endif
</div>
