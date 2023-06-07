<x-layout>
    <h1 class="pb-2">Command Center</h1>
    @if ($servers->count() > 0)
        <livewire:run-command :servers="$servers" />
    @else
        <div>No validated servers found.</div>
    @endif
</x-layout>
