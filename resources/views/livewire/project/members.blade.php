<div>
    <x-slot:title>
        {{ data_get_str($project, 'name')->limit(10) }} > Members | Coolify
    </x-slot>
    <div class="flex flex-col pb-10">
        <div class="flex gap-2">
            <h1>Members</h1>
        </div>
        <div class="pt-2 pb-6">Manage who has access to this project.</div>

        <h2 class="mb-4">Current Members</h2>
        @if($members->isEmpty())
            <p class="text-sm text-neutral-400">No project-specific members yet.</p>
        @else
            <div class="flex flex-col gap-2">
                @foreach($members as $member)
                    <div class="flex items-center justify-between p-3 bg-neutral-800 rounded">
                        <div>
                            <span class="font-medium">{{ $member->user->name }}</span>
                            <span class="text-sm text-neutral-400 ml-2">{{ $member->user->email }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <select wire:change="updateRole({{ $member->id }}, $event.target.value)"
                                class="bg-neutral-700 border border-neutral-600 rounded px-2 py-1 text-sm">
                                <option value="member" @selected($member->role === 'member')>Member</option>
                                <option value="admin" @selected($member->role === 'admin')>Admin</option>
                            </select>
                            <x-forms.button wire:click="removeMember({{ $member->id }})"
                                class="bg-red-600 hover:bg-red-700">Remove</x-forms.button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if($pendingInvitations->isNotEmpty())
            <h2 class="mt-8 mb-4">Pending Invitations</h2>
            <div class="flex flex-col gap-2">
                @foreach($pendingInvitations as $invitation)
                    <div class="flex items-center justify-between p-3 bg-neutral-800 rounded">
                        <div>
                            <span class="font-medium">{{ $invitation->email }}</span>
                            <span class="text-sm text-neutral-400 ml-2">{{ ucfirst($invitation->role) }}</span>
                            @if($invitation->isExpired())
                                <span class="text-sm text-red-400 ml-2">(expired)</span>
                            @endif
                        </div>
                        <x-forms.button wire:click="cancelInvitation({{ $invitation->id }})"
                            class="bg-neutral-600 hover:bg-neutral-700">Cancel</x-forms.button>
                    </div>
                @endforeach
            </div>
        @endif

        <h2 class="mt-8 mb-4">Invite Member</h2>
        <form wire:submit="invite" class="flex gap-2 items-end">
            <x-forms.input label="Email" id="email" wire:model="email" placeholder="user@example.com" />
            <div class="flex flex-col gap-1">
                <label class="text-sm text-neutral-400">Role</label>
                <select wire:model="role" class="bg-neutral-800 border border-neutral-600 rounded px-3 py-2">
                    <option value="member">Member</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <x-forms.button type="submit">Invite</x-forms.button>
        </form>
    </div>
</div>
