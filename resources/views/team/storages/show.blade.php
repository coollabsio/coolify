<x-layout>
    <x-team.navbar :team="auth()
        ->user()
        ->currentTeam()" />
    <livewire:team.storage.form :storage="$storage" />
</x-layout>
