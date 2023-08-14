<x-layout>
    <x-team.navbar :team="auth()
        ->user()
        ->currentTeam()" />
    <livewire:team.form />
    @if (is_cloud())
        <div class="pb-8">
            <h2>Subscription</h2>
            @if (data_get(auth()->user()->currentTeam(),
                    'subscription'))
                <div>Status: {{ auth()->user()->currentTeam()->subscription->lemon_status }}</div>
                <div>Type: {{ auth()->user()->currentTeam()->subscription->lemon_variant_name }}</div>
                @if (auth()->user()->currentTeam()->subscription->lemon_status === 'cancelled')
                    <div class="pb-4">Subscriptions ends at: {{ getRenewDate() }}</div>
                    <x-forms.button class="bg-coollabs-gradient"><a class="text-white hover:no-underline"
                            href="{{ route('subscription') }}">Resume Subscription</a>
                    </x-forms.button>
                    <div class="py-4">If you would like to change the subscription to a lower/higher plan, <a
                            class="text-white underline" href="https://docs.coollabs.io/contact" target="_blank">please
                            contact
                            us.</a></div>
                @else
                    <div class="pb-4">Renews at: {{ getRenewDate() }}</div>
                @endif


                <x-forms.button><a class="text-white hover:no-underline" href="{{ getPaymentLink() }}">Update Payment
                        Details</a>
                </x-forms.button>
            @else
                <x-forms.button class="mt-4"><a class="text-white hover:no-underline"
                        href="{{ route('subscription') }}">Subscribe Now</a>
                </x-forms.button>
            @endif
            <x-forms.button><a class="text-white hover:no-underline"
                    href="https://app.lemonsqueezy.com/my-orders">Manage My
                    Subscription</a>
            </x-forms.button>
        </div>
    @endif
    <livewire:team.delete />
</x-layout>
