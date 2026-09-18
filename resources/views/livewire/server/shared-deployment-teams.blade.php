<x-application.settings-section
    title="Shared deployment access"
    helper="Allow selected teams to deploy their own resources to this server. Shared teams cannot view or configure the server, access its terminal, manage its proxy or security settings, or view resources belonging to other teams.">
    <form wire:submit="save">
        @if ($this->availableTeams->isEmpty())
            <x-callout type="info" title="No other teams available">
                Create another team before sharing this deployment server.
            </x-callout>
        @else
            <div class="space-y-2">
                @foreach ($this->availableTeams as $team)
                    <div
                        class="rounded-lg border border-neutral-200 p-3 dark:border-white/[0.08]"
                        wire:key="shared-deployment-team-{{ $team->id }}">
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
