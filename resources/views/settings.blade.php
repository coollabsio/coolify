<x-layout>
    <livewire:settings.form :settings="$settings" />
    @if (auth()->user()->isPartOfRootTeam())
        <livewire:force-upgrade />
    @endif
</x-layout>
