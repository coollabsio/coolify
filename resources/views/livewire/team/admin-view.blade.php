<div>
    <x-slot:title>
        Team Admin | Coolify
    </x-slot>
    <x-team.navbar />
    <form wire:submit="submitSearch" class="flex flex-col gap-2 lg:flex-row">
        <x-forms.input wire:model="search" placeholder="Search for a user" />
        <x-forms.button type="submit">Search</x-forms.button>
    </form>
    <h3 class="pt-4">Users</h3>
    <div class="flex flex-col gap-2 ">
        @forelse ($users as $user)
            <div class="flex items-center justify-center gap-2 bg-white box-without-bg dark:bg-coolgray-100">
                <div>{{ $user->name }}</div>
                <div>{{ $user->email }}</div>
                <div class="flex-1"></div>
                <div class="flex items-center justify-center gap-2 mx-4 text-xs font-bold ">
                    <x-modal-confirmation isErrorButton action="delete({{ $user->id }})" buttonTitle="Delete">
                        This will delete all resources (application, databases, services, configurations, servers,
                        private keys, tags, etc.) from Coolify and <span
                            class="font-bold text-red-500 dark:text-warning">from the server (if it's reachable)</span>.
                        <br> <br>
                        It is not reversible. <br><br>
                        <div class="font-bold text-red-500 dark:text-white">Think twice!</div>
                    </x-modal-confirmation>
                </div>
            </div>
        @empty
            <div>No users found other than the root.</div>
        @endforelse
        @if ($lots_of_users)
            <div>There are more users than shown. Please use the search bar to find the user you are looking for.</div>
        @endif
    </div>
</div>
