<div>
    <x-slot:title>{{ $project->name }} · Settings | Coolify</x-slot>

    @php
        $sections = [
            'general' => 'General',
            'usage' => 'Usage',
            'environments' => 'Environments',
            'shared-variables' => 'Shared Variables',
            'webhooks' => 'Webhooks',
            'feature-flags' => 'Feature Flags',
            'members' => 'Members',
            'tokens' => 'Tokens',
            'integrations' => 'Integrations',
            'danger' => 'Danger',
        ];
    @endphp

    <x-railway.project-chrome :project="$project" :environment="$environment"
        :projects="$allProjects" :environments="$allEnvironments" active="settings">

        <div class="h-full overflow-y-auto scrollbar">
            <div class="max-w-5xl mx-auto px-8 py-8">
                <h1 class="text-[24px] font-semibold text-rw-text mb-8">Project Settings</h1>

                <div class="flex gap-10">
                    {{-- Sub-nav --}}
                    <nav class="w-48 shrink-0 flex flex-col gap-0.5">
                        @foreach ($sections as $key => $label)
                            <button type="button" wire:click="setSection('{{ $key }}')"
                                class="rw-nav-item hover:rw-nav-item-hover {{ $section === $key ? 'rw-nav-item-active' : '' }} {{ $key === 'danger' && $section !== 'danger' ? 'text-rw-danger' : '' }}">
                                {{ $label }}
                            </button>
                        @endforeach
                    </nav>

                    {{-- Panel --}}
                    <div class="flex-1 min-w-0">
                        @switch($section)
                            @case('general')
                                <div class="max-w-xl flex flex-col gap-5">
                                    <div>
                                        <div class="text-[16px] font-semibold text-rw-text mb-4">Project Info</div>
                                        <label class="block text-[12px] text-rw-muted mb-1.5">Name</label>
                                        <input type="text" wire:model="projectName" class="w-full rounded-md border px-3 h-9 text-[13px] text-rw-text bg-transparent focus:outline-none" style="border-color: var(--color-rw-border); background: var(--color-rw-elevated);" />
                                        @error('projectName') <div class="text-[12px] text-rw-danger mt-1">{{ $message }}</div> @enderror
                                    </div>
                                    <div>
                                        <label class="block text-[12px] text-rw-muted mb-1.5">Description</label>
                                        <input type="text" wire:model="projectDescription" placeholder="Optional description of this project" class="w-full rounded-md border px-3 h-9 text-[13px] text-rw-text bg-transparent focus:outline-none placeholder:text-rw-subtle" style="border-color: var(--color-rw-border); background: var(--color-rw-elevated);" />
                                    </div>
                                    <div>
                                        <label class="block text-[12px] text-rw-muted mb-1.5">Project ID</label>
                                        <div class="flex items-center gap-2 rounded-md border px-3 h-9" style="border-color: var(--color-rw-border); background: var(--color-rw-elevated);">
                                            <span class="text-[13px] font-mono text-rw-muted truncate flex-1">{{ $project->uuid }}</span>
                                            <button type="button" onclick="copyToClipboard('{{ $project->uuid }}')" class="rw-icon-btn hover:rw-icon-btn-hover w-6 h-6">
                                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
                                            </button>
                                        </div>
                                    </div>
                                    <button type="button" wire:click="save" class="rw-btn-primary hover:rw-btn-primary-hover w-fit">Update</button>
                                </div>
                                @break

                            @case('environments')
                                <div class="text-[16px] font-semibold text-rw-text mb-1">Environments</div>
                                <div class="text-[13px] text-rw-subtle mb-4">Each environment gives you an isolated instance of each service.</div>
                                <div class="rw-node !p-0 divide-y" style="--tw-divide-opacity: 1;">
                                    @foreach ($allEnvironments as $env)
                                        <div class="flex items-center gap-3 px-4 py-3 border-b last:border-b-0" style="border-color: var(--color-rw-border);">
                                            @if ($env->uuid === $environment->uuid)
                                                <svg class="w-4 h-4 text-rw-accent" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m5 12 5 5L20 7"/></svg>
                                            @else
                                                <span class="w-4"></span>
                                            @endif
                                            <a href="{{ route('railway.canvas', ['project_uuid' => $project->uuid, 'environment_uuid' => $env->uuid]) }}" wire:navigate class="text-[13px] text-rw-text hover:text-rw-accent flex-1">{{ $env->name }}</a>
                                        </div>
                                    @endforeach
                                </div>
                                @break

                            @case('members')
                                <div class="max-w-2xl flex flex-col gap-6">
                                    <div>
                                        <div class="text-[16px] font-semibold text-rw-text mb-1">Project members</div>
                                        <div class="text-[13px] text-rw-subtle">Members belong to the team and can access every project in it.</div>
                                    </div>

                                    {{-- Invite form --}}
                                    @if ($canManageMembers)
                                        <div class="rw-node flex flex-col gap-3">
                                            <div class="text-[13px] font-semibold text-rw-text">Invite a member</div>
                                            <div class="flex flex-col sm:flex-row gap-2">
                                                <input type="email" wire:model="inviteEmail" wire:keydown.enter="inviteMember(false)" placeholder="name@example.com"
                                                    class="flex-1 rounded-md border px-3 h-9 text-[13px] text-rw-text bg-transparent focus:outline-none placeholder:text-rw-subtle"
                                                    style="border-color: var(--color-rw-border); background: var(--color-rw-elevated);" />
                                                <select wire:model="inviteRole"
                                                    class="rounded-md border px-3 h-9 text-[13px] text-rw-text focus:outline-none"
                                                    style="border-color: var(--color-rw-border); background: var(--color-rw-elevated);">
                                                    <option value="member">Member</option>
                                                    <option value="admin">Admin</option>
                                                    <option value="owner">Owner</option>
                                                </select>
                                            </div>
                                            @error('inviteEmail') <div class="text-[12px] text-rw-danger">{{ $message }}</div> @enderror
                                            <div class="flex items-center gap-2">
                                                <button type="button" wire:click="inviteMember(false)" class="rw-btn-primary hover:rw-btn-primary-hover w-fit">
                                                    <span wire:loading.remove wire:target="inviteMember">Generate invite link</span>
                                                    <span wire:loading wire:target="inviteMember">Working…</span>
                                                </button>
                                                <button type="button" wire:click="inviteMember(true)" class="rw-btn hover:rw-btn-hover w-fit">Send via email</button>
                                            </div>

                                            @if ($generatedInviteLink)
                                                <div class="flex items-center gap-2 rounded-md border px-3 h-9 mt-1" style="border-color: var(--color-rw-accent); background: var(--color-rw-elevated);">
                                                    <span class="text-[12px] font-mono text-rw-muted truncate flex-1">{{ $generatedInviteLink }}</span>
                                                    <button type="button" onclick="copyToClipboard('{{ $generatedInviteLink }}')" class="rw-icon-btn hover:rw-icon-btn-hover w-6 h-6" title="Copy link">
                                                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
                                                    </button>
                                                </div>
                                            @endif
                                        </div>
                                    @endif

                                    {{-- Pending invitations --}}
                                    @if ($invitations->isNotEmpty())
                                        <div>
                                            <div class="text-[13px] font-semibold text-rw-text mb-2">Pending invitations</div>
                                            <div class="rw-node !p-0">
                                                @foreach ($invitations as $invitation)
                                                    <div class="flex items-center gap-3 px-4 py-3 border-b last:border-b-0" style="border-color: var(--color-rw-border);">
                                                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full text-[10px] font-semibold text-white shrink-0" style="background: linear-gradient(135deg,#6b7280,#4b5563);">
                                                            {{ strtoupper(substr($invitation->email, 0, 1)) }}
                                                        </span>
                                                        <div class="min-w-0 flex-1">
                                                            <div class="text-[13px] text-rw-text truncate">{{ $invitation->email }}</div>
                                                            <div class="text-[11px] text-rw-subtle">Invited · {{ ucfirst($invitation->role) }}</div>
                                                        </div>
                                                        <span class="rw-pill">Pending</span>
                                                        <button type="button" wire:click="revokeInvitation({{ $invitation->id }})" wire:confirm="Revoke this invitation?"
                                                            class="text-[12px] text-rw-danger hover:underline">Revoke</button>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif

                                    {{-- Current members --}}
                                    <div>
                                        <div class="text-[13px] font-semibold text-rw-text mb-2">{{ $members->count() }} {{ Illuminate\Support\Str::plural('member', $members->count()) }}</div>
                                        <div class="rw-node !p-0">
                                            @foreach ($members as $member)
                                                @php $memberRole = $member->pivot->role ?? 'member'; @endphp
                                                <div class="flex items-center gap-3 px-4 py-3 border-b last:border-b-0" style="border-color: var(--color-rw-border);">
                                                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-full text-[10px] font-semibold text-white shrink-0" style="background: linear-gradient(135deg,#5b8def,#8b5cf6);">
                                                        {{ strtoupper(substr($member->name ?? $member->email, 0, 1)) }}
                                                    </span>
                                                    <div class="min-w-0 flex-1">
                                                        <div class="text-[13px] text-rw-text truncate">{{ $member->email }}</div>
                                                        @if ($member->id === $currentUserId)
                                                            <div class="text-[11px] text-rw-subtle">You</div>
                                                        @endif
                                                    </div>
                                                    @if ($canManageMembers && $member->id !== $currentUserId)
                                                        <select
                                                            @change="$wire.changeRole({{ $member->id }}, $event.target.value)"
                                                            class="rounded-md border px-2 h-8 text-[12px] text-rw-text focus:outline-none"
                                                            style="border-color: var(--color-rw-border); background: var(--color-rw-elevated);">
                                                            <option value="member" @selected($memberRole === 'member')>Member</option>
                                                            <option value="admin" @selected($memberRole === 'admin')>Admin</option>
                                                            <option value="owner" @selected($memberRole === 'owner')>Owner</option>
                                                        </select>
                                                        <button type="button" wire:click="removeMember({{ $member->id }})" wire:confirm="Remove {{ $member->email }} from the team?"
                                                            class="rw-icon-btn hover:rw-icon-btn-hover w-7 h-7 text-rw-danger" title="Remove member">
                                                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                                                        </button>
                                                    @else
                                                        <span class="rw-pill">{{ ucfirst($memberRole) }}</span>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                                @break

                            @case('danger')
                                <div class="text-[16px] font-semibold text-rw-text mb-1">Manage Services</div>
                                <div class="rounded-md border px-3 py-2.5 mb-4 text-[12px]" style="border-color: color-mix(in srgb, var(--color-rw-danger) 40%, transparent); background: color-mix(in srgb, var(--color-rw-danger) 10%, transparent); color: var(--color-rw-danger);">
                                    Managing services here affects all data and deployments across environments.
                                </div>
                                <div class="rw-node !p-0 mb-8">
                                    @forelse ($resources as $resource)
                                        <div class="flex items-center gap-3 px-4 py-3 border-b last:border-b-0" style="border-color: var(--color-rw-border);">
                                            <x-railway.resource-icon :type="$resource['icon']" size="w-4 h-4" />
                                            <span class="text-[13px] text-rw-text flex-1 truncate">{{ $resource['name'] }}</span>
                                            <a href="{{ $resource['href'] }}" wire:navigate class="rw-btn hover:rw-btn-hover !h-7 !px-2 !text-[12px]">Manage</a>
                                        </div>
                                    @empty
                                        <div class="text-[13px] text-rw-subtle px-4 py-6 text-center">No services in this environment.</div>
                                    @endforelse
                                </div>
                                <div class="text-[16px] font-semibold text-rw-text mb-1">Delete Project</div>
                                <div class="text-[13px] text-rw-subtle mb-3">Permanently delete this project and all of its data. This can't be undone.</div>
                                <a href="{{ route('project.edit', ['project_uuid' => $project->uuid]) }}" wire:navigate class="inline-flex items-center rounded-md px-3 h-9 text-[13px] font-medium text-white" style="background: var(--color-rw-danger);">Delete Project</a>
                                @break

                            @case('shared-variables')
                                <div class="text-[16px] font-semibold text-rw-text mb-1">Shared Variables</div>
                                <div class="text-[13px] text-rw-subtle mb-4">Variables that can be referenced by multiple services within an environment.</div>
                                <a href="{{ route('shared-variables.project.show', ['project_uuid' => $project->uuid]) }}" wire:navigate class="rw-btn hover:rw-btn-hover w-fit">Manage shared variables →</a>
                                @break

                            @default
                                <div class="flex flex-col items-center justify-center gap-2 py-20 text-center">
                                    <div class="text-[15px] font-medium text-rw-text">{{ $sections[$section] ?? ucfirst($section) }}</div>
                                    <div class="text-[13px] text-rw-subtle max-w-sm">This section mirrors Railway's layout. Configuration for self-hosted Coolify lives in the classic settings screens.</div>
                                </div>
                        @endswitch
                    </div>
                </div>
            </div>
        </div>
    </x-railway.project-chrome>
</div>
