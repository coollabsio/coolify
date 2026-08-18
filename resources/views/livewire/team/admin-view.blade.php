<div>
    <x-slot:title>
        Team Admin | Coolify
    </x-slot>

    <x-team.settings-layout>
    <div class="application-settings-form">
        <x-application.settings-section title="Instance users" flush>
            <form wire:submit.prevent="submitSearch"
                class="flex flex-col gap-2 border-b border-neutral-200 p-3 sm:flex-row sm:items-center sm:justify-between dark:border-white/[0.08]">
                <div class="relative w-full max-w-sm">
                    <x-reicon name="search"
                        class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                    <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search users"
                        aria-label="Search users"
                        class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-8! pl-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-accent! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint">
                    <button type="button" wire:click="$set('search', '')" @class([
                        'absolute top-1/2 right-2 flex size-5 -translate-y-1/2 items-center justify-center rounded text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.07] dark:hover:text-fg',
                        'hidden' => blank($search),
                    ]) aria-label="Clear search">
                        <x-reicon name="x" class="size-3" />
                    </button>
                </div>
                <div class="flex w-full gap-2 sm:w-auto">
                    <div class="w-full sm:w-40">
                        <x-forms.listbox id="teamFilter" live :options="[
                            ['value' => 'all', 'label' => 'All users'],
                            ['value' => 'current', 'label' => 'Current team'],
                            ['value' => 'outside', 'label' => 'Outside team'],
                        ]" />
                    </div>
                    <div class="w-full sm:w-40">
                        <x-forms.listbox id="sort" live :options="[
                            ['value' => 'name_asc', 'label' => 'Name A–Z'],
                            ['value' => 'name_desc', 'label' => 'Name Z–A'],
                            ['value' => 'email_asc', 'label' => 'Email A–Z'],
                            ['value' => 'email_desc', 'label' => 'Email Z–A'],
                        ]" />
                    </div>
                </div>
            </form>

            @if ($users->isNotEmpty())
                <div class="data-table transition-opacity"
                    wire:loading.class="opacity-50 pointer-events-none"
                    wire:target="setPage,previousPage,nextPage">
                    <div class="data-table-header admin-users-table-grid">
                        <span>Name</span>
                        <span>Email</span>
                        <span class="text-right">Actions</span>
                    </div>
                    @foreach ($users as $user)
                        <div wire:key="instance-user-{{ $user->id }}"
                            class="data-table-row admin-users-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
                            <div class="flex min-w-0 items-center gap-2">
                                <div
                                    class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-[11px] font-semibold text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">
                                    {{ Str::upper(Str::substr($user->name ?: $user->email, 0, 1)) }}
                                </div>
                                <span class="truncate text-[13px] font-medium text-black dark:text-fg">
                                    {{ $user->name }}
                                </span>
                            </div>
                            <div class="truncate text-[12px] text-neutral-500 dark:text-fg-dim">
                                {{ $user->email }}
                            </div>
                            <div class="flex justify-end">
                                <x-modal-confirmation title="Confirm User Deletion?"
                                    submitAction="delete({{ $user->id }})" :actions="[
                                        'The selected user and their default team resources will be permanently deleted.',
                                    ]"
                                    confirmationText="{{ $user->name }}"
                                    confirmationLabel="Enter the user name to confirm deletion"
                                    shortConfirmationLabel="User name">
                                    <x-slot:trigger>
                                        <button type="button"
                                            class="text-[12px] font-medium text-red-600 transition-colors hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                                            Delete
                                        </button>
                                    </x-slot:trigger>
                                </x-modal-confirmation>
                            </div>
                        </div>
                    @endforeach
                </div>

                <x-table-pagination class="min-h-14 border-t border-neutral-200 dark:border-white/[0.08]"
                    :from="$users->firstItem() ?? 0" :to="$users->lastItem() ?? 0" :total="$users->total()"
                    :current-page="$users->currentPage()" :last-page="$users->lastPage()"
                    wire-target="setPage,previousPage,nextPage" first-action="setPage(1)"
                    previous-action="previousPage" next-action="nextPage"
                    last-action="setPage({{ $users->lastPage() }})">
                    <x-slot:pageSize>
                        <x-page-size-select model="perPage" livewire storage-key="coolify.page-size.team-admin" />
                    </x-slot:pageSize>
                </x-table-pagination>
            @else
                <x-empty title="No users found" description="Try a different name or email address."
                    icon-name="teams" size="sm" />
            @endif
        </x-application.settings-section>
    </div>
    </x-team.settings-layout>
</div>
