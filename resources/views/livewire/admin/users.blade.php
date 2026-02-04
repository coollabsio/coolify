<div>
    <h1 class="text-2xl font-bold mb-6">Global User Management</h1>

    {{-- Create New User --}}
    <div class="mb-8 p-4 bg-coolgray-100 rounded-lg">
        <h2 class="text-lg font-semibold mb-4">Create New User</h2>
        <form wire:submit="createUser" class="flex flex-col lg:flex-row gap-4 items-end">
            <div class="flex-1">
                <x-forms.input wire:model="newUserName" id="newUserName" label="Name" required />
            </div>
            <div class="flex-1">
                <x-forms.input wire:model="newUserEmail" id="newUserEmail" type="email" label="Email" required />
            </div>
            <div class="flex items-center gap-2">
                <x-forms.checkbox wire:model="newUserIsGlobalAdmin" id="newUserIsGlobalAdmin" label="Global Admin" />
            </div>
            <x-forms.button type="submit">Create User</x-forms.button>
        </form>
    </div>

    {{-- Search and Filter --}}
    <div class="mb-6 flex flex-col lg:flex-row gap-4">
        <div class="flex-1">
            <x-forms.input wire:model.live.debounce.300ms="search" id="search" placeholder="Search by name or email..." />
        </div>
        <div class="w-48">
            <x-forms.select wire:model.live="status" id="status">
                <option value="">All Statuses</option>
                <option value="active">Active</option>
                <option value="suspended">Suspended</option>
                <option value="pending">Pending</option>
            </x-forms.select>
        </div>
    </div>

    {{-- Users Table --}}
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="text-xs uppercase bg-coolgray-100">
                <tr>
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3">Email</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Global Admin</th>
                    <th class="px-4 py-3">Teams</th>
                    <th class="px-4 py-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    <tr class="border-b border-coolgray-200 hover:bg-coolgray-100/50">
                        <td class="px-4 py-3 font-medium">
                            {{ $user->name }}
                            @if ($user->id === auth()->id())
                                <span class="text-xs text-warning">(You)</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ $user->email }}</td>
                        <td class="px-4 py-3">
                            <span @class([
                                'px-2 py-1 text-xs rounded',
                                'bg-success/20 text-success' => $user->status === 'active',
                                'bg-error/20 text-error' => $user->status === 'suspended',
                                'bg-warning/20 text-warning' => $user->status === 'pending',
                            ])>
                                {{ ucfirst($user->status ?? 'active') }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            @if ($user->is_global_admin)
                                <span class="text-success">Yes</span>
                            @else
                                <span class="text-neutral-500">No</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1">
                                @foreach ($user->teams as $team)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs bg-coolgray-200 rounded">
                                        {{ $team->name }}
                                        <span class="text-neutral-400">({{ $team->pivot->role }})</span>
                                        @if ($user->id !== auth()->id() || $team->id !== 0)
                                            <button wire:click="removeFromTeam({{ $user->id }}, {{ $team->id }})"
                                                class="text-error hover:text-error/80 ml-1" title="Remove from team">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M6 18L18 6M6 6l12 12"></path>
                                                </svg>
                                            </button>
                                        @endif
                                    </span>
                                @endforeach
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex gap-2">
                                {{-- Toggle Global Admin --}}
                                @if ($user->id !== auth()->id())
                                    <x-forms.button wire:click="toggleGlobalAdmin({{ $user->id }})" isSmall>
                                        {{ $user->is_global_admin ? 'Revoke Admin' : 'Grant Admin' }}
                                    </x-forms.button>
                                @endif

                                {{-- Status Actions --}}
                                @if ($user->id !== auth()->id())
                                    @if ($user->status !== 'suspended')
                                        <x-forms.button wire:click="setStatus({{ $user->id }}, 'suspended')" isSmall
                                            isWarning>
                                            Suspend
                                        </x-forms.button>
                                    @else
                                        <x-forms.button wire:click="setStatus({{ $user->id }}, 'active')" isSmall>
                                            Activate
                                        </x-forms.button>
                                    @endif
                                @endif

                                {{-- Assign to Team --}}
                                <x-forms.button wire:click="selectUserForTeamAssignment({{ $user->id }})" isSmall>
                                    + Team
                                </x-forms.button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-neutral-500">
                            No users found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Pagination --}}
    <div class="mt-4">
        {{ $users->links() }}
    </div>

    {{-- Assign to Team Modal --}}
    @if ($selectedUserId)
        @php
            $selectedUser = $users->firstWhere('id', $selectedUserId);
        @endphp
        <div class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" wire:click.self="$set('selectedUserId', null)">
            <div class="bg-coolgray-100 rounded-lg p-6 max-w-md w-full mx-4">
                <h3 class="text-lg font-semibold mb-4">
                    Assign {{ $selectedUser?->name ?? 'User' }} to Team
                </h3>

                <div class="space-y-4">
                    <x-forms.select wire:model="assignTeamId" id="assignTeamId" label="Select Team">
                        <option value="">-- Select a team --</option>
                        @foreach ($teams as $team)
                            @php
                                $isAlreadyMember = $selectedUser?->teams->contains('id', $team->id);
                            @endphp
                            <option value="{{ $team->id }}" @if ($isAlreadyMember) disabled @endif>
                                {{ $team->name }} @if ($isAlreadyMember)
                                    (already member)
                                @endif
                            </option>
                        @endforeach
                    </x-forms.select>

                    <x-forms.select wire:model="assignRole" id="assignRole" label="Role">
                        @foreach ($roles as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-forms.select>
                </div>

                <div class="flex gap-2 mt-6 justify-end">
                    <x-forms.button wire:click="$set('selectedUserId', null)">
                        Cancel
                    </x-forms.button>
                    <x-forms.button wire:click="assignToTeam" isPrimary>
                        Assign
                    </x-forms.button>
                </div>
            </div>
        </div>
    @endif
</div>
