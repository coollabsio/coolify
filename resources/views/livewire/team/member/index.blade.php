<div x-data="{
    search: '',
    roleFilter: 'all',
    sortBy: 'name_asc',
    page: 1,
    perPage: 10,
    members: @js(currentTeam()->members->map(fn ($member) => [
        'id' => $member->id,
        'name' => $member->name,
        'email' => $member->email,
        'role' => data_get($member, 'pivot.role'),
    ])->values()),
    get filteredMembers() {
        const query = this.search.trim().toLowerCase();

        const filtered = this.members.filter(member => {
            const matchesSearch = !query || [member.name, member.email, member.role]
                .some(value => String(value || '').toLowerCase().includes(query));
            const matchesRole = this.roleFilter === 'all' || member.role === this.roleFilter;

            return matchesSearch && matchesRole;
        });

        return filtered.sort((left, right) => {
            if (this.sortBy === 'name_desc') return right.name.localeCompare(left.name);
            if (this.sortBy === 'email_asc') return left.email.localeCompare(right.email);
            if (this.sortBy === 'role') return left.role.localeCompare(right.role);

            return left.name.localeCompare(right.name);
        });
    },
    get lastPage() {
        return Math.max(1, Math.ceil(this.filteredMembers.length / this.perPage));
    },
    get firstVisibleRow() {
        return this.filteredMembers.length === 0 ? 0 : ((this.page - 1) * this.perPage) + 1;
    },
    get lastVisibleRow() {
        return Math.min(this.page * this.perPage, this.filteredMembers.length);
    },
    isMemberVisible(id) {
        const start = (this.page - 1) * this.perPage;

        return this.filteredMembers
            .slice(start, start + this.perPage)
            .some(member => member.id === id);
    },
    memberOrder(id) {
        return this.filteredMembers.findIndex(member => member.id === id);
    },
    goToPage(page) {
        this.page = Math.min(Math.max(page, 1), this.lastPage);
    }
}">
    <x-slot:title>
        Team Members | Coolify
    </x-slot>

    <x-team.navbar />

    <div class="application-settings-form flex flex-col gap-6">
        <x-application.settings-section title="Members" flush>
            <div
                class="flex flex-col gap-2 border-b border-neutral-200 p-3 sm:flex-row sm:items-center sm:justify-between dark:border-white/[0.08]">
                <div class="relative w-full max-w-sm">
                    <x-reicon name="search"
                        class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                    <input x-model.debounce.150ms="search" x-on:input="page = 1" type="search"
                        placeholder="Search members" aria-label="Search members"
                        class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-8! pl-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-neutral-300! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint">
                    <button x-cloak x-show="search" x-on:click="search = ''; page = 1" type="button"
                        class="absolute top-1/2 right-2 flex size-5 -translate-y-1/2 items-center justify-center rounded text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.07] dark:hover:text-fg"
                        aria-label="Clear search">
                        <x-reicon name="x" class="size-3" />
                    </button>
                </div>
                <div class="flex w-full gap-2 sm:w-auto">
                    <div class="w-full sm:w-36">
                        <x-forms.listbox id="member-role-filter" :wire="false" x-model="roleFilter"
                            x-effect="page = 1" :value="'all'" :options="[
                                ['value' => 'all', 'label' => 'All roles'],
                                ['value' => 'owner', 'label' => 'Owner'],
                                ['value' => 'admin', 'label' => 'Admin'],
                                ['value' => 'member', 'label' => 'Member'],
                            ]" />
                    </div>
                    <div class="w-full sm:w-40">
                        <x-forms.listbox id="member-sort" :wire="false" x-model="sortBy"
                            x-effect="page = 1" :value="'name_asc'" :options="[
                                ['value' => 'name_asc', 'label' => 'Name A–Z'],
                                ['value' => 'name_desc', 'label' => 'Name Z–A'],
                                ['value' => 'email_asc', 'label' => 'Email A–Z'],
                                ['value' => 'role', 'label' => 'Role'],
                            ]" />
                    </div>
                </div>
            </div>

            <div x-cloak x-show="filteredMembers.length > 0" class="data-table flex flex-col">
                <div class="data-table-header team-members-table-grid">
                    <span>Name</span>
                    <span>Email</span>
                    <span>Role</span>
                    <span class="text-right">Actions</span>
                </div>
                @foreach (currentTeam()->members as $member)
                    <livewire:team.member :member="$member" :wire:key="$member->id" />
                @endforeach
            </div>

            <div x-cloak x-show="filteredMembers.length === 0">
                <x-empty size="sm" title="No matching members"
                    description="Try a different name, email address, or role." />
            </div>

            <div x-cloak x-show="filteredMembers.length > 0"
                class="flex min-h-14 items-center justify-between gap-3 border-t border-neutral-200 px-4 py-3 dark:border-white/[0.08]">
                <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                    Showing
                    <span class="tabular-nums text-black dark:text-fg"
                        x-text="`${firstVisibleRow}–${lastVisibleRow}`"></span>
                    of
                    <span class="tabular-nums text-black dark:text-fg" x-text="filteredMembers.length"></span>
                </p>
                <div
                    class="inline-flex h-8 overflow-hidden rounded-lg border border-neutral-200 dark:border-white/[0.10]">
                    <button type="button"
                        class="flex w-10 items-center justify-center border-r border-neutral-200 text-neutral-500 transition-colors hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-35 dark:border-white/[0.10] dark:text-fg-dim dark:hover:bg-white/[0.06]"
                        aria-label="First page" title="First page" x-on:click="goToPage(1)"
                        x-bind:disabled="page === 1">
                        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M18 6L12 12L18 18M11 6L5 12L11 18" stroke="currentColor"
                                stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                    <button type="button"
                        class="flex w-10 items-center justify-center border-r border-neutral-200 text-neutral-500 transition-colors hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-35 dark:border-white/[0.10] dark:text-fg-dim dark:hover:bg-white/[0.06]"
                        aria-label="Previous page" title="Previous page" x-on:click="goToPage(page - 1)"
                        x-bind:disabled="page === 1">
                        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M15 6L9 12L15 18" stroke="currentColor" stroke-width="1.8"
                                stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                    <span
                        class="flex min-w-12 items-center justify-center border-r border-neutral-200 px-3 text-[13px] tabular-nums text-black dark:border-white/[0.10] dark:text-fg"
                        x-text="page"></span>
                    <button type="button"
                        class="flex w-10 items-center justify-center border-r border-neutral-200 text-neutral-500 transition-colors hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-35 dark:border-white/[0.10] dark:text-fg-dim dark:hover:bg-white/[0.06]"
                        aria-label="Next page" title="Next page" x-on:click="goToPage(page + 1)"
                        x-bind:disabled="page === lastPage">
                        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M9 6L15 12L9 18" stroke="currentColor" stroke-width="1.8"
                                stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                    <button type="button"
                        class="flex w-10 items-center justify-center text-neutral-500 transition-colors hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-35 dark:text-fg-dim dark:hover:bg-white/[0.06]"
                        aria-label="Last page" title="Last page" x-on:click="goToPage(lastPage)"
                        x-bind:disabled="page === lastPage">
                        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M6 6L12 12L6 18M13 6L19 12L13 18" stroke="currentColor"
                                stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                </div>
            </div>
        </x-application.settings-section>

        @can('manageInvitations', currentTeam())
            <livewire:team.invite-link />
            <livewire:team.invitations :invitations="$invitations" />
        @endcan
    </div>
</div>
