<div>
    <x-slot:title>
        Team Members | Coolify
    </x-slot>
    <x-team.navbar />
    <h2>Members</h2>

    <div class="flex flex-col">
        <div class="flex flex-col">
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
    </div>
    @if (auth()->user()->isAdminFromSession())
        <div class="py-4">
            @if (is_transactional_emails_active())
                <h2 class="pb-4">Invite New Member</h2>
            @else
                <h2>Invite New Member</h2>
                @if (isInstanceAdmin())
                    <div class="pb-4 text-xs dark:text-warning">You need to configure (as root team) <a href="/settings#smtp"
                            class="underline dark:text-warning">Transactional
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
    @endif
</div>
