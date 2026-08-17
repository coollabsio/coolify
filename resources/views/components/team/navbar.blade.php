@props([
    'title' => 'Team',
    'subtitle' => 'Members, roles, and team settings',
    // Hide family H1 only at xl+; keep create in the layer-2 bar.
    'titleOnDesktop' => false,
])

<x-dashboard.navbar section="team" :title="$title" :subtitle="$subtitle" :titleOnDesktop="$titleOnDesktop">
    @isset($titleActions)
        <x-slot:titleActions>
            {{ $titleActions }}
        </x-slot:titleActions>
    @endisset
    <x-slot:actions>
        @isset($actions)
            {{ $actions }}
        @else
            <x-modal-input title="New Team">
                <x-slot:content>
                    <button type="button"
                        class="button button-highlighted">
                        <x-reicon name="plus" class="size-3.5" />
                        New team
                    </button>
                </x-slot:content>
                <livewire:team.create />
            </x-modal-input>
        @endisset
    </x-slot:actions>
</x-dashboard.navbar>
