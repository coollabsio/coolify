<x-layout>
    <x-team.navbar :team="auth()
        ->user()
        ->currentTeam()" />
    <livewire:team.form />
    @if (is_cloud())
        <div class="pb-8">
            <h3>Subscription</h3>
            @if (data_get(auth()->user()->currentTeam(),
                    'subscription'))
                <div>Status: {{ auth()->user()->currentTeam()->subscription->lemon_status }}</div>
                <div>Type: {{ auth()->user()->currentTeam()->subscription->lemon_variant_name }}</div>
                @if (auth()->user()->currentTeam()->subscription->lemon_status === 'cancelled')
                    <div class="pb-4">Subscriptions ends at: {{ getRenewDate() }}</div>
                    <x-forms.button><a class="text-white" href="{{ route('subscription') }}">Subscribe
                            Again</a>
                    </x-forms.button>
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
