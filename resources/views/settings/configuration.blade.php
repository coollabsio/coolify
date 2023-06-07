<x-layout>
    <x-settings.navbar />
    <livewire:settings.configuration :settings="$settings" />

    @if (auth()->user()->isAdmin())
        <livewire:force-upgrade />
    @endif
</x-layout>
