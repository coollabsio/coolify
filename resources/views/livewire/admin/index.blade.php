<div>
    <h1>Admin Dashboard</h1>
    <h3 class="pt-4">Who am I now?</h3>
    <div class="pb-4">{{ auth()->user()->name }}</div>
    <form wire:submit="submitSearch" class="flex flex-col gap-2 lg:flex-row">
        <x-forms.input wire:model="search" placeholder="Search for a user" />
        <x-forms.button type="submit">Search</x-forms.button>
    </form>
    <div class="pt-4">Active Subscribers : {{ $activeSubscribers }}</div>
    <div>Inactive Subscribers : {{ $inactiveSubscribers }}</div>
    @if ($search)
        @if ($foundUsers->count() > 0)
            <div class="flex flex-wrap gap-2 pt-4">
                @foreach ($foundUsers as $user)
                    <div class="box w-64 group" wire:click="switchUser({{ $user->id }})">
                        <div class="flex flex-col gap-2">
                            <div class="box-title">{{ $user->name }}</div>
                            <div class="box-description">{{ $user->email }}</div>
                            <div class="box-description">Active:
                                {{ $user->teams()->whereRelation('subscription', 'stripe_subscription_id', '!=', null)->exists() ? 'Yes' : 'No' }}
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div>No users found with {{ $search }}</div>
        @endif
    @endif
</div>
