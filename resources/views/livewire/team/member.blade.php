<div wire:key="team-member-row-{{ $member->id }}"
    x-cloak x-show="isMemberVisible({{ $member->id }})"
    x-bind:style="{ order: memberOrder({{ $member->id }}) }"
    class="data-table-row team-members-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
    <div>
        <div class="flex items-center gap-2">
            <div
                class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-[11px] font-semibold text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">
                {{ Str::upper(Str::substr($member->name ?: $member->email, 0, 1)) }}
            </div>
            <span class="truncate text-[13px] font-medium text-black dark:text-fg">{{ $member->name }}</span>
            @if ($member->id === Auth::id())
                <span
                    class="rounded-full bg-coollabs/10 px-1.5 py-0.5 text-[10px] font-medium text-coollabs dark:bg-warning/15 dark:text-warning">
                    You
                </span>
            @endif
        </div>
    </div>
    <div class="truncate text-[12px] text-neutral-500 dark:text-fg-dim">{{ $member->email }}</div>
    <div>
        <span
            class="inline-flex rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] font-medium capitalize text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">
            {{ data_get($member, 'pivot.role') }}
        </span>
    </div>
    <div class="flex justify-end">
        @can('manageMembers', currentTeam())
            @if ($member->id !== Auth::id())
                <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false"
                    @click.outside="open = false">
                    <button type="button" class="button h-7! px-2.5! text-[11px]!" @click="open = !open"
                        aria-haspopup="menu" :aria-expanded="open">
                        Manage
                    </button>
                    <div x-show="open" x-cloak role="menu"
                        class="listbox-panel top-full! right-0! left-auto! mt-1! w-36! min-w-0!">
                        @if (Auth::user()->isOwner())
                            @if (data_get($member, 'pivot.role') !== 'owner')
                                <button type="button" class="listbox-option justify-start!" wire:click="makeOwner"
                                    @click="open = false">
                                    Make owner
                                </button>
                            @endif
                            @if (data_get($member, 'pivot.role') !== 'admin')
                                <button type="button" class="listbox-option justify-start!" wire:click="makeAdmin"
                                    @click="open = false">
                                    Make admin
                                </button>
                            @endif
                            @if (data_get($member, 'pivot.role') !== 'member')
                                <button type="button" class="listbox-option justify-start!"
                                    wire:click="makeReadonly" @click="open = false">
                                    Make member
                                </button>
                            @endif
                        @elseif (Auth::user()->isAdmin())
                            @if (data_get($member, 'pivot.role') === 'admin')
                                <button type="button" class="listbox-option justify-start!"
                                    wire:click="makeReadonly" @click="open = false">
                                    Make member
                                </button>
                            @elseif (data_get($member, 'pivot.role') === 'member')
                                <button type="button" class="listbox-option justify-start!" wire:click="makeAdmin"
                                    @click="open = false">
                                    Make admin
                                </button>
                            @endif
                        @endif
                        <div class="my-1 border-t border-neutral-200 dark:border-white/[0.08]"></div>
                        <button type="button" class="listbox-option justify-start! text-error! hover:text-error!"
                            wire:click="remove" @click="open = false">
                            Remove member
                        </button>
                    </div>
                </div>
            @endif
        @endcan
    </div>
</div>
