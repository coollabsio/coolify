<x-layout>
    <h1>Command Center</h1>
    <nav class="flex pt-2 pb-10 text-sm">
        <ol class="inline-flex items-center">
            <li class="inline-flex items-center">
                Execute commands on your servers without leaving the browser.
            </li>
        </ol>
    </nav>
    @if ($servers->count() > 0)
        <livewire:run-command :servers="$servers" />
    @else
        <div>No validated servers found.
            <x-use-magic-bar />
        </div>
    @endif
</x-layout>
