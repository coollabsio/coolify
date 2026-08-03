@props([
    'title' => 'Keys & Tokens',
    'subtitle' => 'SSH keys, cloud tokens, and API access',
    // Family index/list views hide the H1 only at xl+ (topbar + tabs cover context).
    // Detail views pass true so the resource name stays visible.
    'titleOnDesktop' => false,
])

<x-dashboard.navbar section="security" :title="$title" :subtitle="$subtitle" :titleOnDesktop="$titleOnDesktop">
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
