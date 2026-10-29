<div>
    @can('manageInvitations', currentTeam())
        @if ($invitations->count() > 0)
            <x-application.settings-section title="Pending invitations"
                description="Invitation links that have not been accepted yet." flush>
                <div class="overflow-x-auto">
                    <div class="data-table min-w-[760px] transition-opacity" wire:loading.class="opacity-50 pointer-events-none"
                        wire:target="setSort">
                        <div
                            class="data-table-header grid-cols-[minmax(13rem,1.2fr)_7rem_7rem_minmax(15rem,1.5fr)_6rem]">
                            <x-table.sort-header label="Email" asc="email-asc" desc="email-desc" action="setSort"
                                :current="$sort" />
                            <x-table.sort-header label="Method" asc="via-asc" desc="via-desc" action="setSort"
                                :current="$sort" />
                            <x-table.sort-header label="Role" asc="role-asc" desc="role-desc" first="desc"
                                action="setSort" :current="$sort" />
                            <span>Invitation link</span>
                            <span class="text-right">Actions</span>
                        </div>
                        @foreach ($sortedInvitations as $invite)
                            <div wire:key="team-invitation-{{ $invite->id }}"
                                class="data-table-row grid-cols-[minmax(13rem,1.2fr)_7rem_7rem_minmax(15rem,1.5fr)_6rem] border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
                                <div class="truncate text-[12px] font-medium text-black dark:text-fg">
                                    {{ $invite->email }}
                                </div>
                                <div class="text-[12px] capitalize text-neutral-500 dark:text-fg-dim">
                                    {{ $invite->via }}
                                </div>
                                <div class="text-[12px] capitalize text-neutral-500 dark:text-fg-dim">
                                    {{ $invite->role }}
                                </div>
                                <div class="flex min-w-0 items-center gap-2">
                                    <span
                                        class="min-w-0 truncate font-mono text-[12px] text-neutral-500 dark:text-fg-dim"
                                        title="{{ $invite->link }}">{{ $invite->link }}</span>
                                    <x-copy-button :value="$invite->link" label="Copy invitation link" />
                                </div>
                                <div class="text-right">
                                    <button type="button"
                                        class="button h-7! px-2.5! text-[11px]! text-error! hover:text-error!"
                                        wire:click.prevent="deleteInvitation({{ $invite->id }})">
                                        Revoke
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </x-application.settings-section>
        @endif
    @endcan
</div>
