<form wire:submit="submit" class="application-settings-form mb-6 flex flex-col gap-6">
    <x-unsaved-bar action="submit" />

    <x-application.settings-section id="replicas-section" title="Replicas"
        helper="Run more than one container of this application on one server. The proxy balances the traffic between them.">
        <x-slot:actions>
            <x-status-badge status="Experimental" type="warning" />
        </x-slot:actions>

        @if ($application->build_pack === 'dockercompose')
            <p class="text-[13px] text-neutral-500 dark:text-fg-dim">{{ $unavailableReason }}</p>
        @else
            @if ($unavailableReason)
                <x-callout type="warning" title="Replicas are unavailable" class="mb-4">{{ $unavailableReason }}</x-callout>
            @elseif ($application->persistentStorages()->exists())
                <x-callout type="warning" title="Replicas share persistent storage" class="mb-4">
                    All replicas use the same volumes. Make sure that your application supports this.
                </x-callout>
            @endif

            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input id="replicas" type="number" min="1" :max="\App\Models\Application::MAX_REPLICAS" required
                    label="Replicas" :disabled="filled($unavailableReason)" canGate="update"
                    :canResource="$application" />
            </div>
            <p class="mt-3 text-[13px] text-neutral-500 dark:text-fg-dim">
                Replicas run on one server. To use several servers instead, add them below and put your own load
                balancer in front of them.
            </p>
        @endif
    </x-application.settings-section>
</form>
