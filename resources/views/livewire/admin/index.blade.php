<div>
    <h1>{{ __('admin.dashboard') }}</h1>
    <div class="flex gap-2 pt-4">
        <h3>{{ __('admin.who_am_i') }}</h3>
        @if (session('impersonating'))
            <x-forms.button wire:click="back">{{ __('admin.go_back_root') }}</x-forms.button>
        @endif
    </div>
    <div class="pb-4">{{ auth()->user()->name }} ({{ auth()->user()->email }})</div>
    <form wire:submit="submitSearch" class="flex flex-col gap-2 lg:flex-row">
        <x-forms.input wire:model="search" placeholder="{{ __('admin.search_user_placeholder') }}" />
        <x-forms.button type="submit">{{ __('button.search') }}</x-forms.button>
    </form>
    <div class="pt-4">{{ __('admin.active_subscribers') }} {{ $activeSubscribers }}</div>
    <div>{{ __('admin.inactive_subscribers') }} {{ $inactiveSubscribers }}</div>
    @if ($search)
        @if ($foundUsers->count() > 0)
            <div class="flex flex-wrap gap-2 pt-4">
                @foreach ($foundUsers as $user)
                    <div class="coolbox w-64 group" wire:click="switchUser({{ $user->id }})">
                        <div class="flex flex-col gap-2">
                            <div class="box-title">{{ $user->name }}</div>
                            <div class="box-description">{{ $user->email }}</div>
                            <div class="box-description">{{ __('admin.active') }}
                                {{ $user->teams()->whereRelation('subscription', 'stripe_subscription_id', '!=', null)->exists() ? __('yes') : __('no') }}
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div>{{ __('admin.no_users_found') }} {{ $search }}</div>
        @endif
    @endif
</div>
