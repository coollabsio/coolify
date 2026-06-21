<div>
    <x-slot:title>
        Subscribe | Coolify
    </x-slot>
    @if (auth()->user()->isAdminFromSession())
        <div class="flex gap-2">
            <h1>Subscriptions</h1>
        </div>
        @if ($loading)
            <div class="flex items-center justify-center min-h-[60vh]" wire:init="getStripeStatus">
                <x-loading text="Loading your subscription status..." />
            </div>
        @else
            @if ($isUnpaid)
                <x-banner :closable="false">
                    <div class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-red-500 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd"
                                d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                                clip-rule="evenodd" />
                        </svg>
                        <span><span class="font-bold text-red-500">Payment Failed.</span> Your last payment for Coolify
                            Cloud has failed.</span>
                    </div>
                </x-banner>
                <div>
                    <p class="mb-2">Open the following link, navigate to the button and pay your unpaid/past due
                        subscription.
                    </p>
                    <x-forms.button wire:click='stripeCustomerPortal'>Billing Portal</x-forms.button>
                </div>
            @else
                @if (config('subscription.provider') === 'stripe')
                    @if ($isCancelled)
                        <x-banner :closable="false">
                            <div class="flex items-center gap-2">
                                <svg class="w-5 h-5 text-red-500 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd"
                                        d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                                        clip-rule="evenodd" />
                                </svg>
                                <span><span class="font-bold text-red-500">No Active Subscription.</span> Subscribe to
                                    a plan to start using Coolify Cloud.</span>
                            </div>
                        </x-banner>
                    @endif
                    <div @class(['pt-4 pb-4' => $isCancelled, 'pb-10' => !$isCancelled])></div>
                    <livewire:subscription.pricing-plans />
                @endif
            @endif
        @endif
    @else
        <div class="flex flex-col justify-center mx-10">
            <div class="flex gap-2">
                <h1>Subscription</h1>
            </div>
            <x-callout type="warning" title="Permission Required">
                You are not an admin so you cannot manage your Team's subscription. If this does not make sense, please
                <span class="underline cursor-pointer dark:text-white" wire:click="help">contact us</span>.
            </x-callout>
        </div>
    @endif
</div>
