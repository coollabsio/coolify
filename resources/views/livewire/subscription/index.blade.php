<div>
    <x-slot:title>
        {{ __('subscription.title') }}
    </x-slot>
    @if (auth()->user()->isAdminFromSession())
        @if (request()->query->get('cancelled'))
            <div class="mb-6 rounded-sm alert-error">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 stroke-current shrink-0" fill="none"
                    viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ __('subscription.error_cancelled') }}</span>
            </div>
        @endif
        <div class="flex gap-2">
            <h1>{{ __('subscription.heading') }}</h1>
        </div>
        @if ($loading)
            <div class="flex gap-2" wire:init="getStripeStatus">
                {{ __('subscription.loading') }}
            </div>
        @else
            @if ($isUnpaid)
                <div class="mb-6 rounded-sm alert-error">
                    <span>{{ __('subscription.payment_failed') }}</span>
                </div>
                <div>
                    <p class="mb-2">{{ __('subscription.pay_unpaid') }}
                    </p>
                    <x-forms.button wire:click='stripeCustomerPortal'>{{ __('button.billing_portal') }}</x-forms.button>
                </div>
            @else
                @if (config('subscription.provider') === 'stripe')
                    <div @class([
                        'pb-4' => $isCancelled,
                        'pb-10' => !$isCancelled,
                    ])>
                        @if ($isCancelled)
                            <div class="alert-error">
                                <span>{!! __('subscription.cancelled_notice') !!}</span>
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
                <h1>{{ __('subscription.heading_singular') }}</h1>
            </div>
            <x-callout type="warning" title="{{ __('subscription.permission_required') }}">
                {{ __('subscription.not_admin') }}
                <span class="underline cursor-pointer dark:text-white" wire:click="help">{{ __('subscription.contact_us') }}</span>.
            </x-callout>
        </div>
    @endif
</div>
