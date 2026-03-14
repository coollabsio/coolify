<div>
    <x-slot:title>
        Team Members | Coolify
    </x-slot>
    <x-team.navbar />
    <h2>Members</h2>
    <div class="subtitle">
        Manage or invite members of this team.
    </div>
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
    @can('manageInvitations', currentTeam())
        <div class="py-4">
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

    @if (auth()->user()->isAdmin() || auth()->user()->isOwner())
        @if (count($projectMembers) > 0)
            <div class="pt-6">
                <h2>Project-Specific Members</h2>
                <div class="subtitle">
                    These users have access only to specific projects, not the entire team.
                </div>
                <div class="overflow-x-auto">
                    <div class="inline-block min-w-full">
                        <div class="overflow-hidden">
                            <table class="min-w-full">
                                <thead>
                                    <tr>
                                        <th class="px-5 py-3 text-xs font-medium text-left uppercase">Name</th>
                                        <th class="px-5 py-3 text-xs font-medium text-left uppercase">Email</th>
                                        <th class="px-5 py-3 text-xs font-medium text-left uppercase">Project</th>
                                        <th class="px-5 py-3 text-xs font-medium text-left uppercase">Role</th>
                                        <th class="px-5 py-3 text-xs font-medium text-left uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($projectMembers as $pm)
                                        <tr class="dark:text-white text-black dark:bg-coolblack dark:hover:bg-coolgray-100">
                                            <td class="px-5 py-4 text-sm whitespace-nowrap">{{ $pm->user->name }}</td>
                                            <td class="px-5 py-4 text-sm whitespace-nowrap">{{ $pm->user->email }}</td>
                                            <td class="px-5 py-4 text-sm whitespace-nowrap">
                                                <a {{ wireNavigate() }}
                                                    href="{{ route('project.members', ['project_uuid' => $pm->project->uuid]) }}"
                                                    class="underline">
                                                    {{ $pm->project->name }}
                                                </a>
                                            </td>
                                            <td class="px-5 py-4 text-sm whitespace-nowrap">{{ $pm->role->value }}</td>
                                            <td class="px-5 py-4 text-sm whitespace-nowrap">
                                                <a {{ wireNavigate() }}
                                                    href="{{ route('project.members', ['project_uuid' => $pm->project->uuid]) }}"
                                                    class="underline">
                                                    Manage
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @endif
</div>
