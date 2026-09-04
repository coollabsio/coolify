<x-application.settings-section
    title="Shared build access"
    helper="Allow selected teams to use this dedicated build server. Shared teams cannot view, configure, access the terminal, or deploy resources directly to this server.">
    <form wire:submit="save">
        @if ($this->availableTeams->isEmpty())
            <x-callout type="info" title="No other teams available">
                Create another team before sharing this build server.
            </x-callout>
        @else
            <div class="space-y-2">
                @foreach ($this->availableTeams as $team)
                    <div
                        class="rounded-lg border border-neutral-200 p-3 dark:border-white/[0.08]"
                        wire:key="shared-build-team-{{ $team->id }}">
                        <x-forms.checkbox
                            id="teamAccess.{{ $team->id }}"
                            :label="$team->name"
                            :helper="$team->description ?: 'Team ID: '.$team->id" />
                    </div>
                @endforeach
            </div>

            <div class="mt-4">
                <x-forms.button type="submit">
                    Save shared access
                </x-forms.button>
            </div>
        @endif
    </form>
</x-application.settings-section>
