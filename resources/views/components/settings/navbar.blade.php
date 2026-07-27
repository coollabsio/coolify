<x-dashboard.navbar section="settings">
    @isset($actions)
        <x-slot:actions>
            {{ $actions }}
        </x-slot:actions>
    @endisset
</x-dashboard.navbar>
