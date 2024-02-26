<x-pricing-plans>
    @if (config('subscription.provider') === 'stripe')
        <x-slot:basic>
            <x-forms.button x-show="selected === 'monthly'" x-cloak aria-describedby="tier-basic" class="w-full h-10 buyme"
                wire:click="subscribeStripe('basic-monthly')">
                {{ $isTrial ? 'Start Trial' : 'Subscribe' }}
            </x-forms.button>

            <x-forms.button x-show="selected === 'yearly'" x-cloak aria-describedby="tier-basic" class="w-full h-10 buyme"
                wire:click="subscribeStripe('basic-yearly')">
                {{ $isTrial ? 'Start Trial' : 'Subscribe' }}
            </x-forms.button>
        </x-slot:basic>
        <x-slot:pro>
            <x-forms.button x-show="selected === 'monthly'" x-cloak aria-describedby="tier-pro" class="w-full h-10 buyme"
                wire:click="subscribeStripe('pro-monthly')">
                {{ $isTrial ? 'Start Trial' : 'Subscribe' }}
            </x-forms.button>

            <x-forms.button x-show="selected === 'yearly'" x-cloak aria-describedby="tier-pro" class="w-full h-10 buyme"
                wire:click="subscribeStripe('pro-yearly')"> {{ $isTrial ? 'Start Trial' : 'Subscribe' }}
            </x-forms.button>
        </x-slot:pro>
        <x-slot:ultimate>
            <x-forms.button x-show="selected === 'monthly'" x-cloak aria-describedby="tier-ultimate" class="w-full h-10 buyme"
                wire:click="subscribeStripe('ultimate-monthly')">
                {{ $isTrial ? 'Start Trial' : 'Subscribe' }}
            </x-forms.button>

            <x-forms.button x-show="selected === 'yearly'" x-cloak aria-describedby="tier-ultimate" class="w-full h-10 buyme"
                wire:click="subscribeStripe('ultimate-yearly')"> {{ $isTrial ? 'Start Trial' : 'Subscribe' }}
            </x-forms.button>
        </x-slot:ultimate>
    @endif
    @if (config('subscription.provider') === 'paddle')
        <x-paddle />
    @endif
    @if (config('subscription.provider') === 'lemon')
        <x-slot:basic>
            <x-forms.button x-show="selected === 'monthly'" x-cloak aria-describedby="tier-basic"
                class="w-full h-10 buyme" wire:click="getSubscriptionLink('basic-monthly')"> Subscribe
            </x-forms.button>

            <x-forms.button x-show="selected === 'yearly'" x-cloak aria-describedby="tier-basic"
                class="w-full h-10 buyme" wire:click="getSubscriptionLink('basic-yearly')"> Subscribe
            </x-forms.button>
        </x-slot:basic>
        <x-slot:pro>
            <x-forms.button x-show="selected === 'monthly'" x-cloak aria-describedby="tier-pro"
                class="w-full h-10 buyme" wire:click="getSubscriptionLink('pro-monthly')"> Subscribe
            </x-forms.button>

            <x-forms.button x-show="selected === 'yearly'" x-cloak aria-describedby="tier-pro" class="w-full h-10 buyme"
                wire:click="getSubscriptionLink('pro-yearly')"> Subscribe
            </x-forms.button>
        </x-slot:pro>
        <x-slot:ultimate>
            <x-forms.button x-show="selected === 'monthly'" x-cloak aria-describedby="tier-ultimate"
                class="w-full h-10 buyme" wire:click="getSubscriptionLink('ultimate-monthly')"> Subscribe
            </x-forms.button>

            <x-forms.button x-show="selected === 'yearly'" x-cloak aria-describedby="tier-ultimate"
                class="w-full h-10 buyme" wire:click="getSubscriptionLink('ultimate-yearly')"> Subscribe
            </x-forms.button>
        </x-slot:ultimate>
    @endif
</x-pricing-plans>
