<x-dashboard.navbar section="team">
    <x-slot:actions>
        <x-modal-input title="New Team">
            <x-slot:content>
                <button type="button"
                    class="button bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!">
                    <x-reicon name="plus" class="size-3.5" />
                    New team
                </button>
            </x-slot:content>
            <livewire:team.create />
        </x-modal-input>
    </x-slot:actions>
</x-dashboard.navbar>
