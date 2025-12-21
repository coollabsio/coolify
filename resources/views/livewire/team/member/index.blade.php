<div>
    <x-slot:title>
        Team Members | Coolify
    </x-slot>
    <x-team.navbar />
    <h2>{{ __('teams.members') }}</h2>
    <div class="subtitle">
        {{ __('teams.members_desc') }}
    </div>
    <div class="flex flex-col">
        <div class="flex flex-col">
            <div class="overflow-x-auto">
                <div class="inline-block min-w-full">
                    <div class="overflow-hidden">
                        <table class="min-w-full">
                            <thead>
                                <tr>
                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">{{ __('teams.table_name') }}
                                    </th>
                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">{{ __('teams.table_email') }}</th>
                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">{{ __('teams.table_role') }}</th>
                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">{{ __('teams.table_actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach (currentTeam()->members as $member)
                                    <livewire:team.member :member="$member" :wire:key="$member->id" />
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @can('manageInvitations', currentTeam())
        <div class="py-4">
            @if (is_transactional_emails_enabled())
                <h2 class="pb-4">{{ __('teams.invite_new_member') }}</h2>
            @else
                <h2>{{ __('teams.invite_new_member') }}</h2>
                @if (isInstanceAdmin())
                    <div class="pb-4 text-xs dark:text-warning">{!! __('teams.email_config_warning') !!}</div>
                @endif
            @endif
            <livewire:team.invite-link />
        </div>
        <livewire:team.invitations :invitations="$invitations" />
    @endcan
</div>
