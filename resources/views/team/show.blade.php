<x-layout>
    <x-team.navbar :team="session('currentTeam')" />
    <h2>Members</h2>
    <div class="overflow-x-auto">
        <table class="table">
            <thead>
                <tr class="text-warning border-coolgray-200">
                    <th></th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach (session('currentTeam')->members as $member)
                    <livewire:team.member :member="$member" :wire:key="$member->id" />
                @endforeach
            </tbody>
        </table>
    </div>
    {{-- <div class="py-4">
        <h3>Invite a new member</h3>
        <form class="flex items-center gap-2">
            <x-forms.input type="email" name="email" placeholder="Email" />
            <x-forms.button>Invite</x-forms.button>
        </form>
    </div> --}}
</x-layout>
