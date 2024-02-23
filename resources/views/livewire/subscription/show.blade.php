<div>
    <div >
        <h1>Subscription</h1>
        <div>Here you can see and manage your subscription.</div>
    </div>
    <div class="pb-8">
        @if (data_get(currentTeam(), 'subscription'))
            <livewire:subscription.actions />
        @else
            <div>You are not subscribed to any plan. Please subscribe to a plan to continue.</div>
            <x-forms.button class="mt-4"><a class="text-white hover:no-underline"
                    href="{{ route('subscription.index') }}">Subscribe Now</a>
            </x-forms.button>
        @endif
    </div>
</div>
