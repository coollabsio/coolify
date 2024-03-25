<div>
    <h1>Admin Dashboard</h1>
    <h3 class="pt-4">Who am I now?</h3>
    {{ auth()->user()->name }}
    <h3 class="pt-4">Users</h3>
    <div class="flex flex-wrap gap-2">
        <div class="dark:text-white cursor-pointer w-96 box-without-bg bg-coollabs-100" wire:click="switchUser('0')">
            Root
        </div>
        @foreach ($users as $user)
            <div class="w-96 box" wire:click="switchUser('{{ $user->id }}')">
                <p>{{ $user->name }}</p>
                <p>{{ $user->email }}</p>
            </div>
        @endforeach
    </div>
</div>
