<div>
    <x-team.navbar :team="auth()
        ->user()
        ->currentTeam()" />
    <livewire:team.storage.form :storage="$storage" />
</div>
