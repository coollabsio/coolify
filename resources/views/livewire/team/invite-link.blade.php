@can('manageInvitations', currentTeam())
    <form wire:submit='viaLink' class="flex gap-2 flex-col lg:flex-row items-end">
        <div class="flex flex-1 lg:w-fit w-full gap-2">
            <x-forms.input id="email" type="email" label="Email" name="email" placeholder="{{ __('forms.placeholders.email') }}" required />
            <x-forms.select id="role" name="role" label="{{ __('team.role') }}">
                @if (auth()->user()->role() === 'owner')
                    <option value="owner">{{ __('team.owner') }}</option>
                @endif
                <option value="admin">{{ __('team.admin') }}</option>
                <option value="member">{{ __('team.member') }}</option>
            </x-forms.select>
        </div>
        <div class="flex gap-2 lg:w-fit w-full">
            <x-forms.button type="submit">{{ __('team.generate_invitation_link') }}</x-forms.button>
            @if (is_transactional_emails_enabled())
                <x-forms.button wire:click.prevent='viaEmail'>{{ __('team.send_invitation_via_email') }}</x-forms.button>
            @endif
        </div>
    </form>
@endcan
