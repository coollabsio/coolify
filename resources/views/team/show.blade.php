<x-layout>
    <x-team.navbar :team="auth()
        ->user()
        ->currentTeam()" />
    <livewire:team.form />
    @if (is_cloud())
        <div class="pb-8">
            <h2>Subscription</h2>
            @if (data_get(currentTeam(),
                    'subscription'))
                <livewire:subscription.actions />
            @else
                <x-forms.button class="mt-4"><a class="text-white hover:no-underline"
                        href="{{ route('subscription.show') }}">Subscribe Now</a>
                </x-forms.button>
            @endif

        </div>
    @endif
    <livewire:team.delete />
</x-layout>
