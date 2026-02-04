<div>
    <x-slot:title>
        {{ data_get_str($project, 'name')->limit(10) }} > Access | Coolify
    </x-slot>

    <div class="flex gap-2">
        <h1>{{ data_get_str($project, 'name')->limit(15) }}</h1>
        <div class="subtitle">Access Management</div>
    </div>

    <div class="pt-2 pb-6">
        Manage who can access this project and what they can do.
        @if (!config('constants.features.granular_permissions'))
            <div class="mt-2 text-warning">
                Granular permissions are currently disabled. All team members have access to all projects.
                Enable the feature by setting <code>COOLIFY_GRANULAR_PERMISSIONS=true</code> in your environment.
            </div>
        @endif
    </div>

    {{-- Grant Access Section --}}
    @if (count($teamMembers) > 0)
        <div class="pb-8">
            <h3 class="pb-2">Grant Access</h3>
            <div class="flex gap-4 items-end">
                <div class="w-64">
                    <x-forms.select wire:model="selectedUserId" label="Team Member" id="selectedUserId">
                        <option value="">Select a member...</option>
                        @foreach ($teamMembers as $member)
                            <option value="{{ $member['id'] }}">
                                {{ $member['name'] }} ({{ $member['email'] }})
                            </option>
                        @endforeach
                    </x-forms.select>
                </div>
                <div class="w-48">
                    <x-forms.select wire:model="selectedPermissionLevel" label="Permission Level" id="selectedPermissionLevel">
                        <option value="view_only">View Only</option>
                        <option value="deploy">Deploy</option>
                        <option value="full_access">Full Access</option>
                    </x-forms.select>
                </div>
                <x-forms.button wire:click="grantAccess">Grant Access</x-forms.button>
            </div>
        </div>
    @endif

    {{-- Bulk Actions --}}
    <div class="flex gap-2 pb-4">
        <x-forms.button wire:click="grantFullAccessToAll" wire:confirm="Are you sure you want to grant full access to all team members?">
            Grant Full Access to All Members
        </x-forms.button>
        @if (count($projectUsers) > 0)
            <x-forms.button isError wire:click="revokeAllAccess" wire:confirm="Are you sure you want to revoke all project access?">
                Revoke All Access
            </x-forms.button>
        @endif
    </div>

    {{-- Current Access List --}}
    <div class="pt-4">
        <h3 class="pb-2">Users with Access</h3>
        @if (count($projectUsers) > 0)
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead>
                        <tr class="text-left border-b dark:border-coolgray-400">
                            <th class="px-5 py-3 text-sm font-semibold">Name</th>
                            <th class="px-5 py-3 text-sm font-semibold">Email</th>
                            <th class="px-5 py-3 text-sm font-semibold">Permissions</th>
                            <th class="px-5 py-3 text-sm font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($projectUsers as $projectUser)
                            <tr class="border-b dark:border-coolgray-400 dark:hover:bg-coolgray-100">
                                <td class="px-5 py-4 text-sm">{{ $projectUser['user_name'] }}</td>
                                <td class="px-5 py-4 text-sm">{{ $projectUser['user_email'] }}</td>
                                <td class="px-5 py-4 text-sm">
                                    <div class="flex gap-2">
                                        <span @class([
                                            'px-2 py-1 text-xs rounded',
                                            'bg-green-500/20 text-green-400' => $projectUser['can_view'],
                                            'bg-neutral-500/20 text-neutral-400' => !$projectUser['can_view'],
                                        ])>View</span>
                                        <span @class([
                                            'px-2 py-1 text-xs rounded',
                                            'bg-blue-500/20 text-blue-400' => $projectUser['can_deploy'],
                                            'bg-neutral-500/20 text-neutral-400' => !$projectUser['can_deploy'],
                                        ])>Deploy</span>
                                        <span @class([
                                            'px-2 py-1 text-xs rounded',
                                            'bg-yellow-500/20 text-yellow-400' => $projectUser['can_manage'],
                                            'bg-neutral-500/20 text-neutral-400' => !$projectUser['can_manage'],
                                        ])>Manage</span>
                                        <span @class([
                                            'px-2 py-1 text-xs rounded',
                                            'bg-red-500/20 text-red-400' => $projectUser['can_delete'],
                                            'bg-neutral-500/20 text-neutral-400' => !$projectUser['can_delete'],
                                        ])>Delete</span>
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-sm">
                                    <div class="flex gap-2">
                                        @if ($projectUser['permission_level'] !== 'view_only')
                                            <x-forms.button wire:click="updatePermissions({{ $projectUser['id'] }}, 'view_only')">
                                                View Only
                                            </x-forms.button>
                                        @endif
                                        @if ($projectUser['permission_level'] !== 'deploy')
                                            <x-forms.button wire:click="updatePermissions({{ $projectUser['id'] }}, 'deploy')">
                                                Deploy
                                            </x-forms.button>
                                        @endif
                                        @if ($projectUser['permission_level'] !== 'full_access')
                                            <x-forms.button wire:click="updatePermissions({{ $projectUser['id'] }}, 'full_access')">
                                                Full Access
                                            </x-forms.button>
                                        @endif
                                        <x-forms.button isError wire:click="revokeAccess({{ $projectUser['id'] }})" wire:confirm="Are you sure you want to revoke access?">
                                            Revoke
                                        </x-forms.button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="text-neutral-400">
                No users have been granted explicit access to this project.
                @if (!config('constants.features.granular_permissions'))
                    Since granular permissions are disabled, all team members can access all projects.
                @else
                    Team owners and admins can still access this project via their role.
                @endif
            </div>
        @endif
    </div>

    {{-- Permission Levels Help --}}
    <div class="pt-8">
        <h3 class="pb-2">Permission Levels</h3>
        <div class="grid gap-4 md:grid-cols-3">
            <div class="p-4 rounded bg-coolgray-100">
                <h4 class="font-semibold text-green-400">View Only</h4>
                <p class="text-sm text-neutral-400">Can view project resources, logs, and configurations. Cannot make any changes.</p>
            </div>
            <div class="p-4 rounded bg-coolgray-100">
                <h4 class="font-semibold text-blue-400">Deploy</h4>
                <p class="text-sm text-neutral-400">Can view and deploy applications. Cannot modify configurations or delete resources.</p>
            </div>
            <div class="p-4 rounded bg-coolgray-100">
                <h4 class="font-semibold text-yellow-400">Full Access</h4>
                <p class="text-sm text-neutral-400">Can view, deploy, configure, and delete resources within this project.</p>
            </div>
        </div>
    </div>
</div>
