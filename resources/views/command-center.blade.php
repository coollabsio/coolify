<x-layout>
    <h1>Command Center</h1>
    <div class="pb-10 text-sm breadcrumbs">
        <ul>
            <li>
                Execute commands on your servers without leaving the browser.
            </li>
        </ul>
    </div>
    @if ($servers->count() > 0)
        <livewire:run-command :servers="$servers" />
    @else
        <div>No validated servers found.
            <x-use-magic-bar />
        </div>
    @endif
</x-layout>
