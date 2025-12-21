<div>
    <x-slot:title>
        Team Admin | Coolify
    </x-slot>
    <x-team.navbar />
    <h2>{{ __('teams.admin_view') }}</h2>
    <div class="subtitle">
        {{ __('teams.admin_view_desc') }}
    </div>
    <form wire:submit="submitSearch" class="flex flex-col gap-2 lg:flex-row">
        <x-forms.input wire:model="search" placeholder="{{ __('forms.placeholders.search_user') }}" />
        <x-forms.button type="submit">{{ __('common.search') }}</x-forms.button>
    </form>
    <h3 class="py-4">{{ __('teams.users') }}</h3>
    <div class="grid grid-cols-1 gap-2 lg:grid-cols-2">
        @forelse ($users as $user)
            <div wire:key="user-{{ $user->id }}"
                class="flex items-center justify-center gap-2 bg-white box-without-bg dark:bg-coolgray-100">
                <div>{{ $user->name }}</div>
                <div>{{ $user->email }}</div>
                <div class="flex-1"></div>
                <div class="flex items-center justify-center gap-2 mx-4 text-xs font-bold ">
                    <x-modal-confirmation title="{{ __('modal.confirm_user_deletion') }}" buttonTitle="{{ __('modal.delete_user') }}" isErrorButton
                        submitAction="delete({{ $user->id }})" :actions="[
                            __('teams.delete_user_action_1'),
                            __('teams.delete_user_action_2'),
                        ]"
                        confirmationText="{{ $user->name }}"
                        confirmationLabel="{{ __('teams.confirm_user_deletion_label') }}"
                        shortConfirmationLabel="{{ __('teams.user_name') }}" />
                </div>
            </div>
        @empty
            <div>{{ __('teams.no_users_found') }}</div>
        @endforelse
        @if ($lots_of_users)
            <div>{{ __('teams.more_users_hint') }}</div>
        @endif
    </div>
</div>
