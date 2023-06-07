<x-layout>

    <livewire:settings.form :settings="$settings" />
    <livewire:settings.email :settings="$settings" />

    <h3 class='pb-4'>Actions</h3>
    @if (auth()->user()->isAdmin())
        <livewire:force-upgrade />
    @endif
</x-layout>
