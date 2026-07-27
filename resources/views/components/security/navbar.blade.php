<x-dashboard.navbar section="security">
    @isset($actions)
        <x-slot:actions>
            {{ $actions }}
        </x-slot:actions>
    @endisset
</x-dashboard.navbar>
