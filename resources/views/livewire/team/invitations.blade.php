<div>
    @can('manageInvitations', currentTeam())
        @if ($invitations->count() > 0)
            <x-application.settings-section title="Pending invitations"
                description="Invitation links that have not been accepted yet." flush>
                <div class="overflow-x-auto">
                    <div class="data-table min-w-[760px] transition-opacity" wire:loading.class="opacity-50 pointer-events-none"
                        wire:target="setSort">
                        <div
                            class="data-table-header grid-cols-[minmax(13rem,1.2fr)_7rem_7rem_minmax(15rem,1.5fr)_4.5rem]">
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
                                class="data-table-row grid-cols-[minmax(13rem,1.2fr)_7rem_7rem_minmax(15rem,1.5fr)_4.5rem] border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
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
                                <div class="flex justify-end">
                                    <x-modal-confirmation title="Revoke invitation?"
                                        submitAction="deleteInvitation({{ $invite->id }})"
                                        :actions="['The invitation link for ' . $invite->email . ' stops working.']"
                                        :confirmWithText="false" :confirmWithPassword="false"
                                        step2ButtonText="Revoke invitation">
                                        <x-slot:trigger>
                                            <button type="button"
                                                class="icon-button shrink-0 text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300"
                                                title="Revoke invitation" aria-label="Revoke invitation for {{ $invite->email }}">
                                                <x-reicon name="trash" class="size-3.5" />
                                            </button>
                                        </x-slot:trigger>
                                    </x-modal-confirmation>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </x-application.settings-section>
        @endif
    @endcan
</div>
