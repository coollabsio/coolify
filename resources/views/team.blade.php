<x-layout>
    <h1>Teams</h1>
    <div class="flex gap-2">
        <div>Currently Active Team:</div>
        <div class='text-white'>{{ session('currentTeam')->name }}</div>
    </div>
    <livewire:switch-team>
</x-layout>
