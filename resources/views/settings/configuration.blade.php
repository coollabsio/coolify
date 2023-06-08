<x-layout>
    <x-settings.navbar />
    <livewire:settings.configuration :settings="$settings" />

    @if (auth()->user()->isInstanceAdmin())
        <livewire:force-upgrade />
    @endif
</x-layout>
