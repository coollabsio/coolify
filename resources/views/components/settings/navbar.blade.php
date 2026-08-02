@props([
    'title' => 'Settings',
    'subtitle' => 'Instance configuration and maintenance',
])

<x-dashboard.navbar section="settings" :title="$title" :subtitle="$subtitle">
    @isset($titleActions)
        <x-slot:titleActions>
            {{ $titleActions }}
        </x-slot:titleActions>
    @endisset
    @isset($actions)
        <x-slot:actions>
            {{ $actions }}
        </x-slot:actions>
    @endisset
</x-dashboard.navbar>
