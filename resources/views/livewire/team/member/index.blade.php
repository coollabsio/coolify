<div>
    <x-slot:title>
        Team Members | Coolify
    </x-slot>
    <x-team.navbar />
    <div class="form-section-title mb-6">
        <h2>Members</h2>
    </div>
    <p class="text-sm text-neutral-500 dark:text-neutral-400 -mt-4 mb-4">Manage or invite members of this team.</p>
    <div class="flex flex-col gap-6">
        <div class="form-card">
            <div class="overflow-x-auto">
                <div class="inline-block min-w-full">
                    <div class="overflow-hidden">
                        <table class="min-w-full">
                            <thead>
                                <tr>
                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">Name
                                    </th>
                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">Email</th>
                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">Role</th>
                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">Actions</th>
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
        @can('manageInvitations', currentTeam())
            <div class="form-card">
                @if (is_transactional_emails_enabled())
                    <h2 class="pb-4">Invite New Member</h2>
                @else
                    <h2>Invite New Member</h2>
                    @if (isInstanceAdmin())
                        <div class="pb-4 text-xs dark:text-warning">You need to configure (as root team) <a
                                {{ wireNavigate() }}
                                href="/settings/email" class="underline dark:text-warning">Transactional
                                Emails</a>
                            before
                            you can invite a
                            new
                            member
                            via
                            email.
                        </div>
                    @endif
                @endif
                <livewire:team.invite-link />
            </div>
            <livewire:team.invitations :invitations="$invitations" />
        @endcan
    </div>
</div>
