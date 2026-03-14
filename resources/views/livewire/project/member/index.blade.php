<div>
    <x-slot:title>
        {{ data_get_str($project, 'name')->limit(10) }} > Members | Coolify
    </x-slot>
    <div class="flex gap-2">
        <h1>{{ data_get_str($project, 'name')->limit(15) }} - Members</h1>
    </div>
    <div class="pt-2 pb-6">
        Manage project-specific members who only have access to this project.
    </div>
    <nav class="flex items-center gap-4 pb-4">
        <a {{ wireNavigate() }} href="{{ route('project.edit', ['project_uuid' => $project->uuid]) }}"
            class="dark:text-neutral-400">Settings</a>
        <a class="dark:text-white font-bold"
            href="{{ route('project.members', ['project_uuid' => $project->uuid]) }}">Members</a>
    </nav>

    <h2>Project Members</h2>
    <div class="subtitle">
        These users have access only to this project, not to the entire team.
    </div>
    <div class="flex flex-col">
        @if ($members->count() > 0)
            <div class="overflow-x-auto">
                <div class="inline-block min-w-full">
                    <div class="overflow-hidden">
                        <table class="min-w-full">
                            <thead>
                                <tr>
                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">Name</th>
                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">Email</th>
                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">Role</th>
                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($members as $member)
                                    <livewire:project.member.show-member :projectMember="$member"
                                        :project="$project" :wire:key="'pm-' . $member->id" />
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @else
            <div class="text-neutral-500 dark:text-neutral-400">No project-specific members yet.</div>
        @endif
    </div>

    @if ($this->canManageMembers())
        <div class="py-4">
            @if (is_transactional_emails_enabled())
                <h2 class="pb-4">Invite New Project Member</h2>
            @else
                <h2>Invite New Project Member</h2>
                @if (isInstanceAdmin())
                    <div class="pb-4 text-xs dark:text-warning">You need to configure (as root team)
                        <a {{ wireNavigate() }} href="/settings/email"
                            class="underline dark:text-warning">Transactional Emails</a>
                        before you can invite a new member via email.
                    </div>
                @endif
            @endif
            <livewire:project.member.invite-member :project="$project" />
        </div>

        @if ($invitations->count() > 0)
            <div class="py-4">
                <h2 class="pb-2">Pending Invitations</h2>
                <div class="overflow-x-auto">
                    <div class="inline-block min-w-full">
                        <div class="overflow-hidden">
                            <table class="min-w-full">
                                <thead>
                                    <tr>
                                        <th class="px-5 py-3 text-xs font-medium text-left uppercase">Email</th>
                                        <th class="px-5 py-3 text-xs font-medium text-left uppercase">Role</th>
                                        <th class="px-5 py-3 text-xs font-medium text-left uppercase">Via</th>
                                        <th class="px-5 py-3 text-xs font-medium text-left uppercase">Invitation Link</th>
                                        <th class="px-5 py-3 text-xs font-medium text-left uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($invitations as $invite)
                                        <tr>
                                            <td class="px-5 py-4 text-sm whitespace-nowrap">{{ $invite->email }}</td>
                                            <td class="px-5 py-4 text-sm whitespace-nowrap">{{ $invite->role }}</td>
                                            <td class="px-5 py-4 text-sm whitespace-nowrap">{{ $invite->via }}</td>
                                            <td class="px-5 py-4 text-sm whitespace-nowrap" x-data="checkProtocol">
                                                <template x-if="isHttps">
                                                    <div class="flex gap-2">
                                                        <x-forms.input id="null" type="password"
                                                            value="{{ $invite->link }}" />
                                                        <x-forms.button
                                                            x-on:click="copyToClipboard('{{ $invite->link }}')">Copy
                                                            Link</x-forms.button>
                                                    </div>
                                                </template>
                                                <template x-if="!isHttps">
                                                    <x-forms.input id="null" type="password"
                                                        value="{{ $invite->link }}" />
                                                </template>
                                            </td>
                                            <td class="px-5 py-4 text-sm whitespace-nowrap">
                                                <x-forms.button
                                                    wire:click.prevent='revokeInvitation({{ $invite->id }})'>Revoke
                                                </x-forms.button>
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

@script
    <script>
        Alpine.data('checkProtocol', () => {
            return {
                isHttps: window.location.protocol === 'https:'
            }
        })
    </script>
@endscript
