<x-layout>
    <h1>Command Center</h1>
    <div class="pt-2 pb-10">Execute commands on your servers without leaving the browser.</div>
    @if ($servers->count() > 0)
        <livewire:run-command :servers="$servers"/>
    @else
        <div>
            <div>No validated servers found.</div>
            <x-use-magic-bar/>
        </div>
    @endif
</x-layout>
