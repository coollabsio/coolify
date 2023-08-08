<x-layout>
    <x-team.navbar :team="session('currentTeam')"/>
    <livewire:team.storage.form :storage="$storage"/>
</x-layout>
