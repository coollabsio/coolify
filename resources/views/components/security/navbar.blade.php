@props([
    'title' => 'Keys & Tokens',
    'subtitle' => 'SSH keys, cloud tokens, and API access',
])

<x-dashboard.navbar section="security" :title="$title" :subtitle="$subtitle">
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
