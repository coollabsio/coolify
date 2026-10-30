<div wire:key="team-member-row-{{ $member->id }}"
    x-cloak x-show="isMemberVisible({{ $member->id }})"
    x-bind:style="{ order: memberOrder({{ $member->id }}) }"
    @class([
        'data-table-row team-members-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]',
        'team-members-table-grid-2fa' => auth()->user()?->can('manageMembers', currentTeam()),
    ])>
    <div>
        <div class="flex items-center gap-2">
            @if ($member->avatar_path)
                <img src="{{ profile_avatar_url($member) }}" alt="{{ $member->name }}"
                    class="size-7 shrink-0 rounded-lg object-cover">
            @else
                <div
                    class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-[11px] font-semibold text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">
                    {{ Str::upper(Str::substr($member->name ?: $member->email, 0, 1)) }}
                </div>
            @endif
            <span class="truncate text-[13px] font-medium text-black dark:text-fg">{{ $member->name }}</span>
            @if ($member->id === Auth::id())
                <span
                    class="rounded-full bg-coollabs/10 px-1.5 py-0.5 text-[10px] font-medium text-coollabs dark:bg-brand/15 dark:text-brand-light">
                    You
                </span>
            @endif
        </div>
    </div>
    <div class="min-w-0">
        <div class="truncate text-[12px] text-neutral-500 dark:text-fg-dim">{{ $member->email }}</div>
        @can('manageMembers', currentTeam())
            @if (data_get($member, 'pivot.role') === 'member')
                @php($accessSummary = App\Livewire\Team\Member\Access::summary(data_get($member, 'pivot.access')))
                <div class="mt-0.5 truncate text-[11px] text-neutral-400 dark:text-fg-faint" title="Access: {{ $accessSummary }}">
                    Access: {{ $accessSummary }}
                </div>
            @endif
        @endcan
    </div>
    <div>
        <button type="button"
            class="table-filter-value table-filter-badge inline-flex rounded-full border border-transparent bg-neutral-100 px-2 py-0.5 text-[10px] font-medium capitalize text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim"
            title="Filter by {{ data_get($member, 'pivot.role') }}"
            x-on:click="roleFilter = roleFilter === @js(data_get($member, 'pivot.role')) ? 'all' : @js(data_get($member, 'pivot.role')); page = 1">
            {{ data_get($member, 'pivot.role') }}
        </button>
    </div>
    @can('manageMembers', currentTeam())
        <div class="flex items-center">
            <x-two-factor-badge :enabled="filled($member->two_factor_confirmed_at)" />
        </div>
    @endcan
    <div class="flex justify-end">
        @can('manageMembers', currentTeam())
            @if ($member->id !== Auth::id())
                @if (data_get($member, 'pivot.role') === 'owner' && ! Auth::user()->isOwner())
                    <x-modal-confirmation title="Remove team member?" submitAction="remove"
                        :actions="['This user loses access to all resources of this team.']"
                        :confirmWithText="false" :confirmWithPassword="false"
                        step2ButtonText="Remove member">
                        <x-slot:trigger>
                            <button type="button"
                                class="icon-button shrink-0 text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300"
                                title="Remove member" aria-label="Remove {{ $member->name }}">
                                <x-reicon name="trash" class="size-3.5" />
                            </button>
                        </x-slot:trigger>
                    </x-modal-confirmation>
                @else
                    <x-table.row-actions label="Member actions">
                        @if (Auth::user()->isOwner())
                            @if (data_get($member, 'pivot.role') !== 'owner')
                                <x-table.row-action wire:click="makeOwner">Make owner</x-table.row-action>
                            @endif
                            @if (data_get($member, 'pivot.role') !== 'admin')
                                <x-table.row-action wire:click="makeAdmin">Make admin</x-table.row-action>
                            @endif
                            @if (data_get($member, 'pivot.role') !== 'member')
                                <x-table.row-action wire:click="makeReadonly">Make member</x-table.row-action>
                            @endif
                        @elseif (Auth::user()->isAdmin())
                            @if (data_get($member, 'pivot.role') === 'admin')
                                <x-table.row-action wire:click="makeReadonly">Make member</x-table.row-action>
                            @elseif (data_get($member, 'pivot.role') === 'member')
                                <x-table.row-action wire:click="makeAdmin">Make admin</x-table.row-action>
                            @endif
                        @endif
                        @if (data_get($member, 'pivot.role') === 'member')
                            <x-table.row-action
                                wire:click="$dispatch('open-member-access', { memberId: {{ $member->id }} })">
                                Edit access
                            </x-table.row-action>
                        @endif
                        <x-slot:danger>
                            <x-modal-confirmation title="Remove team member?" buttonFullWidth submitAction="remove"
                                :actions="['This user loses access to all resources of this team.']"
                                :confirmWithText="false" :confirmWithPassword="false"
                                step2ButtonText="Remove member">
                                <x-slot:trigger>
                                    <x-table.row-action>Remove member</x-table.row-action>
                                </x-slot:trigger>
                            </x-modal-confirmation>
                        </x-slot:danger>
                    </x-table.row-actions>
                @endif
            @endif
        @endcan
    </div>
</div>
