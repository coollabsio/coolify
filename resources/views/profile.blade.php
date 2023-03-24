<x-layout>
    <div>
        <div>
            <h3>User</h3>
            <p>Name: {{ auth()->user()->name }}</p>
            <p>Id: {{ auth()->user()->id }}</p>
            <p>Uuid: {{ auth()->user()->uuid }}</p>
        </div>
        <div>
            <h3>Current Team</h3>
            <p>Name: {{ session('currentTeam')->name }}</p>
            <p>Id: {{ session('currentTeam')->id }}</p>
            <p>Uuid: {{ session('currentTeam')->uuid }}</p>
        </div>
        <livewire:switch-team>
    </div>
</x-layout>
