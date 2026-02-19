<div>
    <x-slot:title>
        {{ data_get_str($project, 'name')->limit(10) }} > Members | Coolify
    </x-slot>

    <div class="flex flex-col pb-10">
        <div class="flex gap-2">
            <h1>{{ data_get_str($project, 'name')->limit(15) }}</h1>
        </div>
        <div class="pt-2 pb-10">Manage project members.</div>

        {{-- Navigation Tabs --}}
        <div class="flex gap-2 mb-4 border-b dark:border-gray-700">
            <a href="{{ route('project.edit', ['project_uuid' => $project->uuid]) }}" {{ wireNavigate() }}
                class="px-4 py-2 text-sm font-medium border-b-2 border-transparent hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300">
                General
            </a>
            <a href="{{ route('project.members', ['project_uuid' => $project->uuid]) }}" {{ wireNavigate() }}
                class="px-4 py-2 text-sm font-medium border-b-2 border-primary text-primary">
                Members
            </a>
        </div>

        {{-- Members List --}}
        <div class="flex flex-col gap-4">
            <h2>Current Members</h2>
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
                                @foreach ($project->members as $member)
                                    <tr class="border-b dark:border-gray-700">
                                        <td class="px-5 py-4 text-sm">
                                            {{ $member->name }}
                                            @if ($member->id === auth()->id())
                                                <span class="text-xs text-gray-500">(You)</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 text-sm">{{ $member->email }}</td>
                                        <td class="px-5 py-4 text-sm">
                                            <span class="px-2 py-1 text-xs font-semibold rounded-full
                                                {{ $member->pivot->role === 'admin' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300' }}">
                                                {{ ucfirst($member->pivot->role) }}
                                            </span>
                                        </td>
                                        <td class="px-5 py-4 text-sm">
                                            <div class="flex gap-2">
                                                @if ($member->id !== auth()->id())
                                                    <x-dropdown>
                                                        <x-slot:trigger>
                                                            <x-forms.button type="button" size="sm">Change Role</x-forms.button>
                                                        </x-slot:trigger>
                                                        <x-slot:content>
                                                            @if ($member->pivot->role !== 'admin')
                                                                <x-dropdown.item
                                                                    wire:click="changeRole({{ $member->id }}, 'admin')">
                                                                    Make Admin
                                                                </x-dropdown.item>
                                                            @endif
                                                            @if ($member->pivot->role !== 'member')
                                                                <x-dropdown.item
                                                                    wire:click="changeRole({{ $member->id }}, 'member')">
                                                                    Make Member
                                                                </x-dropdown.item>
                                                            @endif
                                                        </x-slot:content>
                                                    </x-dropdown>
                                                    <x-modal-confirm
                                                        action="removeMember({{ $member->id }})"
                                                        :button-text="'Remove'"
                                                        :title="'Remove Member'"
                                                        :description="'Are you sure you want to remove ' . $member->name . ' from this project?'"
                                                    />
                                                @else
                                                    <span class="text-xs text-gray-500">-</span>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            @if ($project->members->isEmpty())
                <div class="text-center text-gray-500 py-8">
                    No project members yet. Add members below.
                </div>
            @endif
        </div>

        {{-- Add Member Form --}}
        <div class="pt-8">
            @if (is_transactional_emails_enabled())
                <h2 class="pb-4">Add New Member</h2>
            @else
                <h2>Add New Member</h2>
                @if (isInstanceAdmin())
                    <div class="pb-4 text-xs dark:text-warning">
                        You need to configure <a href="/settings/email" {{ wireNavigate() }} class="underline dark:text-warning">Transactional Emails</a>
                        before you can invite a new member via email.
                    </div>
                @endif
            @endif

            <form wire:submit="addMember" class="flex flex-col gap-4">
                <div class="flex gap-2">
                    <div class="flex-1">
                        <x-forms.input
                            label="Email"
                            id="email"
                            type="email"
                            wire:model="email"
                            placeholder="user@example.com"
                            required
                        />
                    </div>
                    <div class="w-48">
                        <x-forms.select
                            label="Role"
                            id="role"
                            wire:model="role"
                            required
                        >
                            <option value="member">Member</option>
                            <option value="admin">Admin</option>
                        </x-forms.select>
                    </div>
                </div>
                <div class="text-sm text-gray-500">
                    <p><strong>Member:</strong> Can view and interact with project resources.</p>
                    <p><strong>Admin:</strong> Can manage project members and settings.</p>
                </div>
                <div>
                    <x-forms.button type="submit">Add Member</x-forms.button>
                </div>
            </form>
        </div>
    </div>
</div>
