<div x-data="{
    search: '',
    roleFilter: 'all',
    sortBy: 'name_asc',
    page: 1,
    perPage: 10,
    members: @js($members->map(fn ($member) => [
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

    <x-team.settings-layout>
    <div class="application-settings-form flex flex-col gap-6">
        <x-application.settings-section title="Members" flush>
            <div
                class="flex flex-col gap-2 border-b border-neutral-200 p-3 sm:flex-row sm:items-center sm:justify-between dark:border-white/[0.08]">
                <div class="relative w-full max-w-sm">
                    <x-reicon name="search"
                        class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                    <input x-model.debounce.150ms="search" x-on:input="page = 1" type="search"
                        placeholder="Search members" aria-label="Search members"
                        class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-8! pl-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-accent! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint">
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

            @can('manageMembers', currentTeam())
                <div
                    class="border-b border-neutral-200 px-4 py-2.5 text-[12px] dark:border-white/[0.08]">
                    @if ($membersWithoutTwoFactorCount > 0)
                        <span class="text-warning-700 dark:text-warning">{{ $membersWithoutTwoFactorCount }} of {{ $members->count() }} {{ Str::plural('member', $members->count()) }} {{ $membersWithoutTwoFactorCount === 1 ? 'does' : 'do' }} not have two-factor authentication enabled.</span>
                    @else
                        <span class="text-neutral-500 dark:text-fg-dim">All members have two-factor authentication enabled.</span>
                    @endif
                </div>
            @endcan

            <div x-cloak x-show="filteredMembers.length > 0" class="data-table flex flex-col">
                <div @class([
                    'data-table-header team-members-table-grid',
                    'team-members-table-grid-2fa' => auth()->user()?->can('manageMembers', currentTeam()),
                ])>
                    <span>Name</span>
                    <span>Email</span>
                    <span>Role</span>
                    @can('manageMembers', currentTeam())
                        <span>2FA</span>
                    @endcan
                    <span class="text-right">Actions</span>
                </div>
                @foreach ($members as $member)
                    <livewire:team.member :member="$member" :wire:key="$member->id" />
                @endforeach
            </div>

            <div x-cloak x-show="filteredMembers.length === 0">
                <x-empty size="sm" title="No matching members"
                    description="Try a different name, email address, or role." />
            </div>

            <x-client-pagination x-cloak x-show="filteredMembers.length > 0"
                summary="`${firstVisibleRow}-${lastVisibleRow} of ${filteredMembers.length}`"
                page-size-model="perPage" storage-key="coolify.page-size.team-members"
                previous-action="goToPage(page - 1)" next-action="goToPage(page + 1)"
                next-disabled="page >= lastPage" />
        </x-application.settings-section>

        @can('manageInvitations', currentTeam())
            <livewire:team.invite-link />
            <livewire:team.invitations :invitations="$invitations" />
        @endcan
    </div>
    </x-team.settings-layout>
</div>
