<div>
    <h1>Admin Dashboard</h1>
    <h3 class="pt-4">Who am I now?</h3>
    <div class="pb-4">{{ auth()->user()->name }}</div>
    <form wire:submit="submitSearch" class="flex flex-col gap-2 lg:flex-row">
        <x-forms.input wire:model="search" placeholder="Search for a user" />
        <x-forms.button type="submit">Search</x-forms.button>
    </form>
    <h3 class="pt-4">Active Subscribers</h3>
    <div class="flex flex-wrap gap-2">
        @forelse ($active_subscribers as $user)
            <div class="flex gap-2 box" wire:click="switchUser('{{ $user->id }}')">
                <p>{{ $user->name }}</p>
                <p>{{ $user->email }}</p>
            </div>
        @empty
            <p>No active subscribers</p>
        @endforelse
    </div>
    <h3 class="pt-4">Inactive Subscribers</h3>
    <div class="flex flex-col flex-wrap gap-2">
        @forelse ($inactive_subscribers as $user)
            <div class="flex gap-2 box" wire:click="switchUser('{{ $user->id }}')">
                <p>{{ $user->name }}</p>
                <p>{{ $user->email }}</p>
            </div>
        @empty
            <p>No inactive subscribers</p>
        @endforelse
    </div>
</div>
