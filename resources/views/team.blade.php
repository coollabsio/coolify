<x-layout>
    <div>
        <h3>Current Team</h3>
        <p>Name: {{ session('currentTeam.name') }}</p>
        <livewire:switch-team/>

    </div>
</x-layout>
